<?php

namespace Tests\Feature\GrupB;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Events\KekuranganBahan;
use App\Models\AiDraft;
use App\Models\BahanBaku;
use App\Models\JadwalDistribusi;
use App\Models\JadwalProduksi;
use App\Models\LogProduksi;
use App\Models\Menu;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

class JadwalProduksiTest extends GrupBTestCase
{
    private function payload(Menu $menu, array $override = []): array
    {
        return array_merge([
            'menu_id' => $menu->id, 'tanggal_produksi' => now()->addDay()->toDateString(),
            'jam_mulai_masak' => '03:00', 'target_porsi' => 1000, 'catatan' => 'Hari pertama',
        ], $override);
    }

    private function menu(?int $sppgId = null): Menu
    {
        return Menu::factory()->create(['sppg_id' => $sppgId ?? $this->sppgA->id]);
    }

    public function test_kepala_crud_jadwal_produksi(): void
    {
        $menu = $this->menu();
        $kepala = $this->login(Role::KepalaSppg);

        $id = $this->postJson('/api/v1/jadwal', $this->payload($menu))
            ->assertCreated()
            ->assertJsonPath('data.status', 'direncanakan')
            ->assertJsonPath('data.sppg_id', $this->sppgA->id)
            ->assertJsonPath('data.dibuat_oleh', $kepala->id)
            ->assertJsonPath('data.jam_mulai_masak', '03:00')
            ->assertJsonPath('data.menu.nama_menu', $menu->nama_menu)
            ->assertJsonPath('data.layak_distribusi', false)
            ->json('data.id');

        $this->getJson("/api/v1/jadwal/{$id}")->assertOk()->assertJsonPath('data.target_porsi', 1000);
        $this->patchJson("/api/v1/jadwal/{$id}", ['target_porsi' => 1200, 'catatan' => 'Naik'])->assertOk()->assertJsonPath('data.target_porsi', 1200);
        $this->deleteJson("/api/v1/jadwal/{$id}")->assertNoContent();
        $this->assertSoftDeleted('jadwal_produksis', ['id' => $id]);
        $this->getJson("/api/v1/jadwal/{$id}")->assertNotFound();
    }

    public function test_validasi_422(): void
    {
        $menuB = $this->menu($this->sppgB->id);
        $menu = $this->menu();
        $this->login(Role::KepalaSppg);

        $this->postJson('/api/v1/jadwal', [])->assertUnprocessable()->assertJsonValidationErrors(['menu_id', 'tanggal_produksi', 'target_porsi']);
        $this->postJson('/api/v1/jadwal', $this->payload($menuB))->assertUnprocessable()->assertJsonValidationErrors(['menu_id']);
        $this->postJson('/api/v1/jadwal', $this->payload($menu, ['tanggal_produksi' => now()->subDay()->toDateString()]))->assertUnprocessable()->assertJsonValidationErrors(['tanggal_produksi']);
        $this->postJson('/api/v1/jadwal', $this->payload($menu, ['target_porsi' => 0]))->assertUnprocessable()->assertJsonValidationErrors(['target_porsi']);
        $this->postJson('/api/v1/jadwal', $this->payload($menu, ['jam_mulai_masak' => '25:99']))->assertUnprocessable()->assertJsonValidationErrors(['jam_mulai_masak']);
        $this->postJson('/api/v1/jadwal', $this->payload($menu, ['status' => 'selesai']))->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->assertSame(0, JadwalProduksi::query()->count());
    }

    public function test_validasi_kapasitas_harian(): void
    {
        $menu = $this->menu();
        $tanggal = now()->addDays(2)->toDateString();
        $this->login(Role::KepalaSppg);

        $res = $this->postJson('/api/v1/jadwal', $this->payload($menu, ['tanggal_produksi' => $tanggal, 'target_porsi' => 3500]))->assertUnprocessable();
        $this->assertSame('Target porsi 3500 melebihi kapasitas harian SPPG (3000 porsi).', $res->json('errors.target_porsi.0'));

        $this->postJson('/api/v1/jadwal', $this->payload($menu, ['tanggal_produksi' => $tanggal, 'target_porsi' => 2000]))->assertCreated();
        $res = $this->postJson('/api/v1/jadwal', $this->payload($menu, ['tanggal_produksi' => $tanggal, 'target_porsi' => 1500]))->assertUnprocessable();
        $this->assertStringContainsString('Total jadwal tanggal '.$tanggal.' menjadi 3500 porsi (2000 sudah terjadwal + 1500 baru), melebihi kapasitas harian 3000 porsi. Sisa kapasitas 1000 porsi.', $res->json('errors.target_porsi.0'));

        // Tepat memenuhi kapasitas diperbolehkan; tanggal lain tidak terpengaruh.
        $this->postJson('/api/v1/jadwal', $this->payload($menu, ['tanggal_produksi' => $tanggal, 'target_porsi' => 1000]))->assertCreated();
        $this->postJson('/api/v1/jadwal', $this->payload($menu, ['tanggal_produksi' => now()->addDays(3)->toDateString(), 'target_porsi' => 3000]))->assertCreated();
    }

    public function test_kapasitas_tidak_menghitung_batal_dan_update_mengecualikan_diri(): void
    {
        $menu = $this->menu();
        $tanggal = now()->addDays(2)->toDateString();
        $batal = JadwalProduksi::factory()->create(['sppg_id' => $this->sppgA->id, 'menu_id' => $menu->id, 'tanggal_produksi' => $tanggal, 'target_porsi' => 2500, 'status' => 'dibatalkan']);
        $aktif = JadwalProduksi::factory()->create(['sppg_id' => $this->sppgA->id, 'menu_id' => $menu->id, 'tanggal_produksi' => $tanggal, 'target_porsi' => 1000]);
        $lain = JadwalProduksi::factory()->create(['sppg_id' => $this->sppgB->id, 'tanggal_produksi' => $tanggal, 'target_porsi' => 3000]);
        $this->login(Role::KepalaSppg);

        $this->postJson('/api/v1/jadwal', $this->payload($menu, ['tanggal_produksi' => $tanggal, 'target_porsi' => 2000]))->assertCreated();
        $this->patchJson("/api/v1/jadwal/{$aktif->id}", ['target_porsi' => 1000])->assertOk();
        $this->patchJson("/api/v1/jadwal/{$aktif->id}", ['target_porsi' => 1001])->assertUnprocessable()->assertJsonValidationErrors(['target_porsi']);
        $this->assertNotNull($batal);
        $this->assertNotNull($lain);
    }

    public function test_hak_akses_per_unit_dan_peran(): void
    {
        $menu = $this->menu();
        $jadwal = JadwalProduksi::factory()->create(['sppg_id' => $this->sppgA->id, 'menu_id' => $menu->id]);

        $this->login(Role::Akuntan);
        $this->getJson('/api/v1/jadwal')->assertForbidden();
        $this->getJson("/api/v1/jadwal/{$jadwal->id}/kebutuhan-bahan")->assertForbidden();

        foreach ([Role::StafGudang, Role::Admin] as $role) {
            $this->login($role);
            $this->getJson('/api/v1/jadwal')->assertOk();
            $this->getJson("/api/v1/jadwal/{$jadwal->id}/kebutuhan-bahan")->assertOk();
            $this->postJson('/api/v1/jadwal', $this->payload($menu))->assertForbidden();
            $this->patchJson("/api/v1/jadwal/{$jadwal->id}", ['catatan' => 'x'])->assertForbidden();
            $this->deleteJson("/api/v1/jadwal/{$jadwal->id}")->assertForbidden();
        }

        $this->login(Role::AhliGizi);
        $this->postJson('/api/v1/jadwal', $this->payload($menu))->assertCreated();
        $this->deleteJson("/api/v1/jadwal/{$jadwal->id}")->assertForbidden();

        $this->login(Role::Relawan, null, RelawanUnit::Dapur);
        $this->getJson('/api/v1/jadwal')->assertOk();
        $this->postJson('/api/v1/jadwal', $this->payload($menu, ['tanggal_produksi' => now()->addDays(5)->toDateString()]))->assertCreated();
        $this->getJson('/api/v1/jadwal/distribusi')->assertForbidden();

        $this->login(Role::Relawan, null, RelawanUnit::Distribusi);
        $this->getJson('/api/v1/jadwal')->assertForbidden();
        $this->postJson('/api/v1/jadwal', $this->payload($menu))->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/jadwal')->assertUnauthorized();
    }

    public function test_relawan_dapur_hanya_mengubah_miliknya_hari_yang_sama(): void
    {
        $menu = $this->menu();
        $relawan = $this->user(Role::Relawan, null, RelawanUnit::Dapur);
        $milikSendiri = JadwalProduksi::factory()->create(['sppg_id' => $this->sppgA->id, 'menu_id' => $menu->id, 'dibuat_oleh' => $relawan->id, 'tanggal_produksi' => now()->addDay()->toDateString()]);
        $milikLain = JadwalProduksi::factory()->create(['sppg_id' => $this->sppgA->id, 'menu_id' => $menu->id, 'dibuat_oleh' => null, 'tanggal_produksi' => now()->addDays(2)->toDateString()]);
        $kemarin = JadwalProduksi::factory()->create(['sppg_id' => $this->sppgA->id, 'menu_id' => $menu->id, 'dibuat_oleh' => $relawan->id, 'tanggal_produksi' => now()->addDays(3)->toDateString()]);
        $kemarin->forceFill(['created_at' => now()->subDay()])->saveQuietly();
        Sanctum::actingAs($relawan);

        $this->patchJson("/api/v1/jadwal/{$milikSendiri->id}", ['catatan' => 'diubah'])->assertOk();
        $this->patchJson("/api/v1/jadwal/{$milikLain->id}", ['catatan' => 'diubah'])->assertForbidden();
        $this->patchJson("/api/v1/jadwal/{$kemarin->id}", ['catatan' => 'diubah'])->assertForbidden();
        $this->deleteJson("/api/v1/jadwal/{$milikSendiri->id}")->assertForbidden();
    }

    public function test_lintas_sppg_dan_admin(): void
    {
        $milikB = JadwalProduksi::factory()->create(['sppg_id' => $this->sppgB->id]);
        JadwalProduksi::factory()->create(['sppg_id' => $this->sppgA->id]);

        $this->login(Role::KepalaSppg);
        $this->getJson("/api/v1/jadwal/{$milikB->id}")->assertNotFound();
        $this->patchJson("/api/v1/jadwal/{$milikB->id}", ['catatan' => 'x'])->assertNotFound();
        $this->deleteJson("/api/v1/jadwal/{$milikB->id}")->assertNotFound();
        $this->getJson("/api/v1/jadwal/{$milikB->id}/kebutuhan-bahan")->assertNotFound();
        $this->getJson('/api/v1/jadwal')->assertOk()->assertJsonCount(1, 'data');

        $this->login(Role::Admin);
        $this->getJson('/api/v1/jadwal')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/jadwal?sppg_id='.$this->sppgB->id)->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_status_transisi_dan_penguncian(): void
    {
        $menu = $this->menu();
        $menu2 = $this->menu();
        $jadwal = JadwalProduksi::factory()->create(['sppg_id' => $this->sppgA->id, 'menu_id' => $menu->id, 'tanggal_produksi' => now()->addDay()->toDateString()]);
        $this->login(Role::KepalaSppg);
        $url = "/api/v1/jadwal/{$jadwal->id}";

        $this->patchJson($url, ['status' => 'selesai'])->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->patchJson($url, ['status' => 'dimasak'])->assertOk()->assertJsonPath('data.status', 'dimasak');
        $this->patchJson($url, ['target_porsi' => 10])->assertUnprocessable()->assertJsonValidationErrors(['target_porsi']);
        $this->patchJson($url, ['menu_id' => $menu2->id])->assertUnprocessable()->assertJsonValidationErrors(['menu_id']);
        $this->patchJson($url, ['catatan' => 'Mulai masak'])->assertOk();
        $this->patchJson($url, ['status' => 'direncanakan'])->assertUnprocessable();
        $this->patchJson($url, ['status' => 'selesai'])->assertOk()->assertJsonPath('data.status', 'selesai');
        $this->patchJson($url, ['status' => 'dimasak'])->assertUnprocessable();
        $this->patchJson($url, ['status' => 'dibatalkan'])->assertUnprocessable();
        $this->deleteJson($url)->assertUnprocessable()->assertJsonValidationErrors(['status']);
    }

    public function test_pembatalan_membatalkan_distribusi_menunggu_namun_ditolak_bila_sudah_berangkat(): void
    {
        $a = JadwalProduksi::factory()->create(['sppg_id' => $this->sppgA->id, 'target_porsi' => 1000]);
        $b = JadwalProduksi::factory()->create(['sppg_id' => $this->sppgA->id, 'target_porsi' => 1000]);
        $menunggu = JadwalDistribusi::factory()->create(['sppg_id' => $this->sppgA->id, 'jadwal_produksi_id' => $a->id, 'jumlah_porsi' => 100]);
        $siap = JadwalDistribusi::factory()->siap()->create(['sppg_id' => $this->sppgA->id, 'jadwal_produksi_id' => $a->id, 'jumlah_porsi' => 100]);
        JadwalDistribusi::factory()->berangkat()->create(['sppg_id' => $this->sppgA->id, 'jadwal_produksi_id' => $b->id, 'jumlah_porsi' => 100]);
        $this->login(Role::KepalaSppg);

        $this->patchJson("/api/v1/jadwal/{$b->id}", ['status' => 'dibatalkan'])->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->patchJson("/api/v1/jadwal/{$a->id}", ['status' => 'dibatalkan'])->assertOk();
        $this->assertSame('dibatalkan', $menunggu->fresh()->status->value);
        $this->assertSame('dibatalkan', $siap->fresh()->status->value);
    }

    public function test_hapus_ditolak_bila_ada_log_atau_distribusi_aktif(): void
    {
        $dist = JadwalProduksi::factory()->create(['sppg_id' => $this->sppgA->id]);
        JadwalDistribusi::factory()->create(['sppg_id' => $this->sppgA->id, 'jadwal_produksi_id' => $dist->id, 'jumlah_porsi' => 10]);
        $log = JadwalProduksi::factory()->create(['sppg_id' => $this->sppgA->id]);
        LogProduksi::factory()->create(['sppg_id' => $this->sppgA->id, 'jadwal_produksi_id' => $log->id]);
        $this->login(Role::KepalaSppg);

        $this->deleteJson("/api/v1/jadwal/{$dist->id}")->assertUnprocessable()->assertJsonValidationErrors(['jadwal']);
        $this->deleteJson("/api/v1/jadwal/{$log->id}")->assertUnprocessable()->assertJsonValidationErrors(['jadwal']);
    }

    public function test_target_tidak_boleh_di_bawah_porsi_distribusi(): void
    {
        $jadwal = JadwalProduksi::factory()->create(['sppg_id' => $this->sppgA->id, 'target_porsi' => 1000, 'tanggal_produksi' => now()->addDay()->toDateString()]);
        JadwalDistribusi::factory()->create(['sppg_id' => $this->sppgA->id, 'jadwal_produksi_id' => $jadwal->id, 'jumlah_porsi' => 600]);
        $this->login(Role::KepalaSppg);

        $this->patchJson("/api/v1/jadwal/{$jadwal->id}", ['target_porsi' => 500])->assertUnprocessable()->assertJsonValidationErrors(['target_porsi']);
        $this->patchJson("/api/v1/jadwal/{$jadwal->id}", ['target_porsi' => 600])->assertOk();
    }

    public function test_kebutuhan_bahan_dibandingkan_dengan_stok(): void
    {
        $beras = BahanBaku::factory()->create(['sppg_id' => $this->sppgA->id, 'nama' => 'Beras', 'satuan' => 'kg', 'stok' => 100]);
        $ayam = BahanBaku::factory()->create(['sppg_id' => $this->sppgA->id, 'nama' => 'Ayam', 'satuan' => 'kg', 'stok' => 500]);
        $telur = BahanBaku::factory()->create(['sppg_id' => $this->sppgA->id, 'nama' => 'Telur', 'satuan' => 'butir', 'stok' => 10]);
        $menu = $this->menu();
        $menu->bahanBakus()->attach([
            $beras->id => ['gram_per_porsi' => 150],   // 1000 porsi -> 150 kg, stok 100 -> kurang 50
            $ayam->id => ['gram_per_porsi' => 100],    // 100 kg, stok 500 -> cukup
            $telur->id => ['gram_per_porsi' => 60],    // butir: tak dapat dibandingkan
        ]);
        $jadwal = JadwalProduksi::factory()->create(['sppg_id' => $this->sppgA->id, 'menu_id' => $menu->id, 'target_porsi' => 1000]);
        $this->login(Role::KepalaSppg);

        $res = $this->getJson("/api/v1/jadwal/{$jadwal->id}/kebutuhan-bahan")->assertOk()
            ->assertJsonPath('data.cukup', false)
            ->assertJsonPath('data.jumlah_bahan_kurang', 1)
            ->assertJsonPath('data.jumlah_bahan_tidak_dapat_dibandingkan', 1)
            ->assertJsonPath('data.target_porsi', 1000);
        $bahan = collect($res->json('data.bahan'))->keyBy('nama');
        $this->assertEquals(150, $bahan['Beras']['kebutuhan']);
        $this->assertEquals(50, $bahan['Beras']['kekurangan']);
        $this->assertFalse($bahan['Beras']['cukup']);
        $this->assertEquals(100, $bahan['Ayam']['kebutuhan']);
        $this->assertSame(0, (int) $bahan['Ayam']['kekurangan']);
        $this->assertTrue($bahan['Ayam']['cukup']);
        $this->assertFalse($bahan['Telur']['dapat_dibandingkan']);
        $this->assertNull($bahan['Telur']['kekurangan']);
        $this->assertEquals(150000, $bahan['Beras']['kebutuhan_gram']);
    }

    public function test_event_kekurangan_bahan_dipancarkan_saat_jadwal_dibuat(): void
    {
        $beras = BahanBaku::factory()->create(['sppg_id' => $this->sppgA->id, 'nama' => 'Beras', 'satuan' => 'kg', 'stok' => 10]);
        $menu = $this->menu();
        $menu->bahanBakus()->attach($beras->id, ['gram_per_porsi' => 100]);
        $this->login(Role::KepalaSppg);

        Event::fake([KekuranganBahan::class]);
        $res = $this->postJson('/api/v1/jadwal', $this->payload($menu, ['target_porsi' => 500]))->assertCreated()
            ->assertJsonCount(1, 'kekurangan_bahan');

        Event::assertDispatched(KekuranganBahan::class, function (KekuranganBahan $e) use ($res, $beras) {
            return $e->sppgId === $this->sppgA->id
                && $e->jadwalProduksiId === $res->json('data.id')
                && $e->targetPorsi === 500
                && $e->kekurangan[0]['bahan_baku_id'] === $beras->id
                && $e->kekurangan[0]['kekurangan'] === 40.0;
        });

        Event::fake([KekuranganBahan::class]);
        $this->postJson('/api/v1/jadwal', $this->payload($menu, ['target_porsi' => 50, 'tanggal_produksi' => now()->addDays(4)->toDateString()]))->assertCreated()
            ->assertJsonCount(0, 'kekurangan_bahan');
        Event::assertNotDispatched(KekuranganBahan::class);
    }

    public function test_pencarian_filter_paginasi(): void
    {
        $menuA = Menu::factory()->create(['sppg_id' => $this->sppgA->id, 'nama_menu' => 'Nasi Ikan Lele']);
        $menuB = Menu::factory()->create(['sppg_id' => $this->sppgA->id, 'nama_menu' => 'Bubur Ayam']);
        JadwalProduksi::factory()->create(['sppg_id' => $this->sppgA->id, 'menu_id' => $menuA->id, 'tanggal_produksi' => '2026-11-03', 'catatan' => 'Pagi']);
        JadwalProduksi::factory()->create(['sppg_id' => $this->sppgA->id, 'menu_id' => $menuB->id, 'tanggal_produksi' => '2026-11-04', 'status' => 'selesai', 'catatan' => 'Siang']);
        JadwalProduksi::factory()->count(3)->create(['sppg_id' => $this->sppgA->id, 'menu_id' => $menuB->id, 'tanggal_produksi' => '2026-12-01']);
        $this->login(Role::KepalaSppg);

        $this->getJson('/api/v1/jadwal?q=lele')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/jadwal?q=Siang')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/jadwal?status=selesai')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/jadwal?tanggal=2026-11-03')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/jadwal?from=2026-11-01&to=2026-11-30')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/jadwal?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 5);
    }

    public function test_draf_ai_dikonfirmasi(): void
    {
        $menu = $this->menu();
        $user = $this->user(Role::KepalaSppg);
        $draf = AiDraft::factory()->create(['sppg_id' => $this->sppgA->id, 'user_id' => $user->id, 'module' => 'jadwal']);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/jadwal', $this->payload($menu, ['ai_draft_id' => $draf->uuid]))->assertCreated();
        $this->assertSame('confirmed', $draf->fresh()->status->value);
        $this->postJson('/api/v1/jadwal', $this->payload($menu, ['ai_draft_id' => $draf->uuid, 'tanggal_produksi' => now()->addDays(6)->toDateString()]))->assertUnprocessable()->assertJsonValidationErrors(['ai_draft_id']);
        $this->assertSame(1, JadwalProduksi::query()->count());
    }
}
