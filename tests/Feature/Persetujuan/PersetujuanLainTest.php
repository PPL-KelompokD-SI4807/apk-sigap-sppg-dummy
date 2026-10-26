<?php

namespace Tests\Feature\Persetujuan;

use App\Enums\Role;
use App\Enums\StatusPengajuanMenu;
use App\Events\MenuDiajukan;
use App\Events\MenuDiputuskan;
use App\Models\JadwalProduksi;
use App\Models\Menu;
use App\Models\PengajuanMenu;
use App\Models\User;
use App\Services\Ai\AiAgentService;
use App\Services\Ai\AiInput;
use App\Services\Ai\Llm\FakeLlmClient;
use App\Services\Ai\Llm\LlmClient;
use App\Services\Ai\Llm\LlmResponse;
use App\Services\Ai\Tools\ToolRegistry;
use App\Services\Notification\DomainEventNotifications;
use App\Support\Rbac;
use Illuminate\Http\UploadedFile;
use LogicException;

/** Imutabilitas, gate jadwal, notifikasi, RBAC investor, dan tool AI baca (R-06b-1). */
class PersetujuanLainTest extends PersetujuanTestCase
{
    // ------------------------------------------------------------------ imutabilitas model

    private function pengajuanContoh(): PengajuanMenu
    {
        $this->forgetActor();

        return PengajuanMenu::factory()->create(['sppg_id' => $this->sppgA, 'menu_id' => Menu::factory()->diajukan()->create(['sppg_id' => $this->sppgA])->id]);
    }

    public function test_model_menolak_hapus_dan_ubah_snapshot(): void
    {
        $p = $this->pengajuanContoh();

        foreach ([['biaya_per_porsi', 1], ['total_biaya', 1], ['jumlah_porsi', 1], ['menu_versi', 9], ['rincian', ['x' => 1]], ['catatan_pengaju', 'ubah']] as [$kolom, $nilai]) {
            $segar = PengajuanMenu::query()->withoutGlobalScopes()->findOrFail($p->id);
            $segar->{$kolom} = $nilai;

            try {
                $segar->save();
                $this->fail("Perubahan {$kolom} harus ditolak.");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(LogicException::class);
        $p->delete();
    }

    public function test_hanya_transisi_tunggal_diajukan_ke_status_akhir(): void
    {
        $p = $this->pengajuanContoh();
        $segar = PengajuanMenu::query()->withoutGlobalScopes()->findOrFail($p->id);

        // Kolom keputusan + snapshot sekaligus: ditolak.
        $segar->status = StatusPengajuanMenu::Disetujui;
        $segar->total_biaya = 1;
        $this->expectLogicOrContinue(fn () => $segar->save());

        $ok = PengajuanMenu::query()->withoutGlobalScopes()->findOrFail($p->id);
        $ok->forceFill(['status' => StatusPengajuanMenu::Ditolak, 'diputuskan_pada' => now(), 'catatan_keputusan' => 'Tidak sesuai'])->save();
        $this->assertSame('ditolak', $ok->fresh()->status->value);

        // Sudah final: tidak boleh berubah lagi (termasuk kembali ke diajukan atau status lain).
        foreach ([StatusPengajuanMenu::Disetujui, StatusPengajuanMenu::Diajukan, StatusPengajuanMenu::Dibatalkan] as $status) {
            $akhir = PengajuanMenu::query()->withoutGlobalScopes()->findOrFail($p->id);
            $akhir->status = $status;
            $this->expectLogicOrContinue(fn () => $akhir->save());
        }

        $this->assertSame('ditolak', PengajuanMenu::query()->withoutGlobalScopes()->findOrFail($p->id)->status->value);
    }

    private function expectLogicOrContinue(callable $aksi): void
    {
        try {
            $aksi();
            $this->fail('LogicException diharapkan.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
    }

    // ------------------------------------------------------------------ gate jadwal produksi

    private function jadwalBody(Menu $menu): array
    {
        return ['menu_id' => $menu->id, 'tanggal_produksi' => now()->addDay()->toDateString(), 'target_porsi' => 200];
    }

    public function test_jadwal_menolak_menu_belum_disetujui_dan_menerima_disetujui(): void
    {
        $this->forgetActor();

        foreach (['draf', 'diajukan', 'ditolak', 'perlu_revisi'] as $status) {
            $menu = Menu::factory()->create(['sppg_id' => $this->sppgA, 'status_persetujuan' => $status]);
            $this->sebagai($this->kepalaA)->postJson('/api/v1/jadwal', $this->jadwalBody($menu))
                ->assertUnprocessable()->assertJsonValidationErrors(['menu_id']);
        }

        $this->assertSame(0, JadwalProduksi::query()->withoutGlobalScopes()->count());

        $disetujui = Menu::factory()->create(['sppg_id' => $this->sppgA, 'status_persetujuan' => 'disetujui']);
        $this->postJson('/api/v1/jadwal', $this->jadwalBody($disetujui))->assertCreated();
    }

    public function test_ubah_menu_jadwal_ke_menu_belum_disetujui_ditolak_tetapi_menu_lama_boleh_tetap(): void
    {
        $this->forgetActor();
        $lama = Menu::factory()->create(['sppg_id' => $this->sppgA]);
        $baru = Menu::factory()->draf()->create(['sppg_id' => $this->sppgA]);
        $jadwal = JadwalProduksi::factory()->create(['sppg_id' => $this->sppgA, 'menu_id' => $lama->id, 'tanggal_produksi' => now()->addDay()->toDateString()]);

        // Menu lama kembali ke draf setelah diubah: jadwal lama tetap dapat diubah (menu_id tidak diganti).
        Menu::withoutGlobalScopes()->whereKey($lama->id)->update(['status_persetujuan' => 'draf']);
        $this->sebagai($this->kepalaA)->patchJson("/api/v1/jadwal/{$jadwal->id}", ['menu_id' => $lama->id, 'target_porsi' => 300])->assertOk();
        $this->patchJson("/api/v1/jadwal/{$jadwal->id}", ['menu_id' => $baru->id])->assertUnprocessable()->assertJsonValidationErrors(['menu_id']);
    }

    // ------------------------------------------------------------------ notifikasi

    public function test_menu_diajukan_sampai_ke_investor_pemilik_bukan_investor_lain(): void
    {
        $menu = $this->menuSiapAjukan();
        $this->forgetActor();
        $this->ajukan($menu);

        $chat = fn (User $u) => (int) $u->telegram_id;
        $kirim = $this->telegramCalls('sendMessage')->map(fn ($r) => (int) $r['chat_id'])->all();

        $this->assertContains($chat($this->investorAB), $kirim);
        $this->assertNotContains($chat($this->investorC), $kirim);
        $this->assertNotContains($chat($this->kepalaA), $kirim);

        $pesan = $this->messagesTo($chat($this->investorAB))->first();
        $this->assertStringContainsString('Nasi Telur Uji', $pesan['text']);
        $this->assertStringContainsString('6.000', $pesan['text']);
        $this->assertStringContainsString('60.000', $pesan['text']);
        $tombol = $this->markupOf($pesan)['inline_keyboard'][0][0];
        $this->assertSame('Lihat di Mini App', $tombol['text']);
        $this->assertStringContainsString('/persetujuan-menu/', $tombol['web_app']['url']);
        $this->assertStringContainsString("?sppg={$this->sppgA}", $tombol['web_app']['url']);
    }

    public function test_menu_diputuskan_sampai_ke_ahli_gizi_dan_dedup(): void
    {
        $menu = $this->menuSiapAjukan();
        $id = $this->ajukan($menu);

        $this->sebagai($this->investorAB, $this->sppgA)
            ->postJson("/api/v1/persetujuan-menu/{$id}/keputusan", ['keputusan' => 'perlu_revisi', 'catatan' => 'Tambah <b>sayur</b>', 'versi_menu' => 1])
            ->assertOk();

        $pesan = $this->messagesTo((int) $this->ahliGiziA->telegram_id)->last();
        $this->assertNotNull($pesan);
        $this->assertStringContainsString('PERLU REVISI', $pesan['text']);
        $this->assertStringContainsString('Tambah &lt;b&gt;sayur&lt;/b&gt;', $pesan['text']);
        $this->assertNotContains((int) $this->investorC->telegram_id, $this->telegramCalls('sendMessage')->map(fn ($r) => (int) $r['chat_id'])->all());

        // Dedup per pengajuan: event identik tidak mengirim ulang.
        $sebelum = $this->messagesTo((int) $this->ahliGiziA->telegram_id)->count();
        $p = PengajuanMenu::query()->withoutGlobalScopes()->findOrFail($id);
        event(MenuDiputuskan::dari($p));
        $this->assertSame($sebelum, $this->messagesTo((int) $this->ahliGiziA->telegram_id)->count());
    }

    public function test_event_terdaftar_dan_penerima_sesuai_peran(): void
    {
        $this->assertContains('MenuDiajukan', DomainEventNotifications::EVENTS);
        $this->assertContains('MenuDiputuskan', DomainEventNotifications::EVENTS);
        $this->assertContains(MenuDiajukan::class, DomainEventNotifications::registeredEventClasses());
        $this->assertSame([Role::Investor], DomainEventNotifications::RECIPIENTS['MenuDiajukan']);
        $this->assertSame([Role::AhliGizi], DomainEventNotifications::RECIPIENTS['MenuDiputuskan']);
    }

    // ------------------------------------------------------------------ RBAC investor

    public function test_investor_tetap_readonly_dengan_hak_keputusan_dan_lampiran_ditolak(): void
    {
        $this->assertTrue(Rbac::readOnly($this->investorAB));
        $this->assertTrue(Rbac::canDecide($this->investorAB, 'persetujuan-menu'));

        foreach ([$this->admin, $this->kepalaA, $this->ahliGiziA, $this->akuntanA, $this->stafGudangA, $this->relawanA] as $u) {
            $this->assertFalse(Rbac::canDecide($u, 'persetujuan-menu'), $u->role->value);
        }

        $this->assertContains('persetujuan-menu:putuskan', Rbac::abilities($this->investorAB));
        $this->assertNotContains('persetujuan-menu:putuskan', Rbac::abilities($this->kepalaA));
        $this->assertSame(['persetujuan-menu' => 'R'], array_intersect_key(Rbac::permissions($this->investorAB), ['persetujuan-menu' => 1]));
        foreach (['menu-gizi', 'pemasok', 'jadwal'] as $modul) {
            $this->assertSame('', Rbac::letters($this->investorAB, $modul), $modul);
        }

        $this->sebagai($this->investorAB, $this->sppgA)->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('user.baca_saja', true)
            ->assertJsonPath('user.keputusan', ['persetujuan-menu']);
        $this->sebagai($this->kepalaA)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('user.keputusan', []);

        $this->sebagai($this->investorAB, $this->sppgA);
        $this->post('/api/v1/lampiran', ['modul' => 'keuangan-pengeluaran', 'lampiranable_id' => 1, 'kategori' => 'nota', 'berkas' => UploadedFile::fake()->create('a.jpg', 5, 'image/jpeg')], ['Accept' => 'application/json'])
            ->assertForbidden();
        $this->getJson('/api/v1/menu-gizi')->assertForbidden();
        $this->getJson('/api/v1/jadwal')->assertForbidden();
        $this->getJson('/api/v1/pemasok')->assertForbidden();
    }

    // ------------------------------------------------------------------ Agen AI

    public function test_tool_ai_baca_pengajuan_mewarisi_matriks_dan_tidak_ada_tool_tulis(): void
    {
        $registry = app(ToolRegistry::class);

        foreach ([$this->investorAB, $this->kepalaA, $this->ahliGiziA, $this->admin] as $u) {
            $this->assertArrayHasKey('daftar_pengajuan_menu', $registry->forUser($u), $u->role->value);
        }

        foreach ([$this->akuntanA, $this->stafGudangA, $this->relawanA] as $u) {
            $this->assertArrayNotHasKey('daftar_pengajuan_menu', $registry->forUser($u), $u->role->value);
        }

        $semua = array_keys($registry->all());
        $this->assertSame(['daftar_pengajuan_menu'], array_values(array_filter($semua, fn ($n) => str_contains($n, 'pengajuan') || str_contains($n, 'persetujuan'))));
    }

    public function test_tool_ai_hanya_menampilkan_sppg_aktif(): void
    {
        $menuA = $this->menuSiapAjukan();
        $this->ajukan($menuA);
        $menuC = $this->menuSiapAjukan($this->sppgC);
        $ahliC = $this->userOf(Role::AhliGizi, $this->sppgC);
        $this->sebagai($ahliC)->postJson("/api/v1/menu-gizi/{$menuC->id}/ajukan", ['versi' => 1])->assertCreated();

        $llm = new FakeLlmClient;
        $this->app->instance(LlmClient::class, $llm);
        $llm->push(LlmResponse::toolCall('daftar_pengajuan_menu', []))->push(LlmResponse::text('Selesai.'));
        $this->forgetActor();

        $this->app->make(AiAgentService::class)->handle($this->investorC, AiInput::text('daftar pengajuan menu'));

        $isi = json_encode($llm->calls, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Nasi Telur Uji', $isi);
        $this->assertSame(1, PengajuanMenu::query()->withoutGlobalScopes()->where('sppg_id', $this->sppgC)->count());
        $this->assertStringContainsString('total\\":1', $isi);
    }
}
