<?php

namespace Tests\Feature\GrupB;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Enums\StatusAiDraft;
use App\Models\AiDraft;
use App\Models\AuditLog;
use App\Models\BahanBaku;
use App\Models\JadwalProduksi;
use App\Models\Menu;
use App\Models\StandarGizi;
use Laravel\Sanctum\Sanctum;

class MenuGiziTest extends GrupBTestCase
{
    private function payload(array $override = []): array
    {
        return array_merge([
            'nama_menu' => 'Nasi Ayam Sayur Bayam', 'kelompok_sasaran' => 'siswa', 'tanggal_sajian' => '2026-11-02',
            'siklus' => 'Siklus 1', 'energi_kkal' => 620, 'protein_g' => 22, 'lemak_g' => 18, 'karbohidrat_g' => 90, 'serat_g' => 7,
        ], $override);
    }

    public function test_ahli_gizi_crud_menu_dengan_komposisi(): void
    {
        $beras = BahanBaku::factory()->create(['sppg_id' => $this->sppgA->id, 'nama' => 'Beras']);
        $ayam = BahanBaku::factory()->create(['sppg_id' => $this->sppgA->id, 'nama' => 'Ayam']);
        $this->login(Role::AhliGizi);

        $id = $this->postJson('/api/v1/menu-gizi', $this->payload(['bahan' => [
            ['bahan_baku_id' => $beras->id, 'gram_per_porsi' => 120],
            ['bahan_baku_id' => $ayam->id, 'gram_per_porsi' => 60.5],
        ]]))->assertCreated()
            ->assertJsonPath('data.sppg_id', $this->sppgA->id)
            ->assertJsonCount(2, 'data.bahan')
            ->assertJsonPath('data.peringatan_gizi', [])
            ->json('data.id');

        $this->getJson("/api/v1/menu-gizi/{$id}")->assertOk()->assertJsonPath('data.nama_menu', 'Nasi Ayam Sayur Bayam')->assertJsonPath('data.kelompok_sasaran_label', 'Siswa');
        $this->patchJson("/api/v1/menu-gizi/{$id}", ['nama_menu' => 'Nasi Ayam Bayam'])->assertOk()->assertJsonPath('data.nama_menu', 'Nasi Ayam Bayam')->assertJsonCount(2, 'data.bahan');
        $this->putJson("/api/v1/menu-gizi/{$id}", $this->payload(['bahan' => [['bahan_baku_id' => $beras->id, 'gram_per_porsi' => 150]]]))
            ->assertOk()->assertJsonCount(1, 'data.bahan')->assertJsonPath('data.bahan.0.gram_per_porsi', 150);
        $this->putJson("/api/v1/menu-gizi/{$id}", $this->payload(['bahan' => []]))->assertOk()->assertJsonCount(0, 'data.bahan');
        $this->deleteJson("/api/v1/menu-gizi/{$id}")->assertNoContent();
        $this->assertSoftDeleted('menus', ['id' => $id]);
        $this->getJson("/api/v1/menu-gizi/{$id}")->assertNotFound();
    }

    public function test_validasi_422(): void
    {
        $bahanB = BahanBaku::factory()->create(['sppg_id' => $this->sppgB->id]);
        $bahanA = BahanBaku::factory()->create(['sppg_id' => $this->sppgA->id]);
        $this->login(Role::AhliGizi);

        $this->postJson('/api/v1/menu-gizi', [])->assertUnprocessable()->assertJsonValidationErrors(['nama_menu', 'kelompok_sasaran']);
        $this->postJson('/api/v1/menu-gizi', $this->payload(['kelompok_sasaran' => 'hewan']))->assertUnprocessable()->assertJsonValidationErrors(['kelompok_sasaran']);
        $this->postJson('/api/v1/menu-gizi', $this->payload(['energi_kkal' => -5]))->assertUnprocessable()->assertJsonValidationErrors(['energi_kkal']);
        $this->postJson('/api/v1/menu-gizi', $this->payload(['bahan' => [['bahan_baku_id' => $bahanB->id, 'gram_per_porsi' => 10]]]))->assertUnprocessable()->assertJsonValidationErrors(['bahan.0.bahan_baku_id']);
        $this->postJson('/api/v1/menu-gizi', $this->payload(['bahan' => [['bahan_baku_id' => $bahanA->id, 'gram_per_porsi' => 0]]]))->assertUnprocessable()->assertJsonValidationErrors(['bahan.0.gram_per_porsi']);
        $this->postJson('/api/v1/menu-gizi', $this->payload(['bahan' => [
            ['bahan_baku_id' => $bahanA->id, 'gram_per_porsi' => 10], ['bahan_baku_id' => $bahanA->id, 'gram_per_porsi' => 20],
        ]]))->assertUnprocessable()->assertJsonValidationErrors(['bahan.0.bahan_baku_id', 'bahan.1.bahan_baku_id']);
        $this->assertSame(0, Menu::query()->count());
    }

    public function test_hak_akses_per_peran(): void
    {
        $menu = Menu::factory()->create(['sppg_id' => $this->sppgA->id]);

        foreach ([Role::KepalaSppg, Role::StafGudang, Role::Admin] as $role) {
            $this->login($role);
            $this->getJson('/api/v1/menu-gizi')->assertOk();
            $this->getJson("/api/v1/menu-gizi/{$menu->id}")->assertOk();
            $this->postJson('/api/v1/menu-gizi', $this->payload())->assertForbidden();
            $this->putJson("/api/v1/menu-gizi/{$menu->id}", $this->payload())->assertForbidden();
            $this->deleteJson("/api/v1/menu-gizi/{$menu->id}")->assertForbidden();
            $this->postJson('/api/v1/menu-gizi/standar-gizi', [])->assertForbidden();
        }

        $this->login(Role::Relawan, null, RelawanUnit::Distribusi);
        $this->getJson('/api/v1/menu-gizi')->assertOk();
        $this->postJson('/api/v1/menu-gizi', $this->payload())->assertForbidden();

        $this->login(Role::Akuntan);
        $this->getJson('/api/v1/menu-gizi')->assertForbidden();
        $this->getJson("/api/v1/menu-gizi/{$menu->id}/ringkasan-gizi")->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/menu-gizi')->assertUnauthorized();
    }

    public function test_lintas_sppg_dan_admin(): void
    {
        $milikB = Menu::factory()->create(['sppg_id' => $this->sppgB->id, 'nama_menu' => 'Menu B']);
        Menu::factory()->create(['sppg_id' => $this->sppgA->id, 'nama_menu' => 'Menu A']);
        StandarGizi::factory()->create(['sppg_id' => $this->sppgB->id]);

        $this->login(Role::AhliGizi);
        $this->getJson("/api/v1/menu-gizi/{$milikB->id}")->assertNotFound();
        $this->putJson("/api/v1/menu-gizi/{$milikB->id}", $this->payload())->assertNotFound();
        $this->deleteJson("/api/v1/menu-gizi/{$milikB->id}")->assertNotFound();
        $this->getJson("/api/v1/menu-gizi/{$milikB->id}/ringkasan-gizi")->assertNotFound();
        $this->getJson('/api/v1/menu-gizi')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/menu-gizi/standar-gizi')->assertOk()->assertJsonCount(0, 'data');

        $this->login(Role::Admin);
        $this->getJson('/api/v1/menu-gizi')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/menu-gizi?sppg_id='.$this->sppgB->id)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/menu-gizi/{$milikB->id}")->assertOk();
    }

    public function test_pencarian_filter_dan_paginasi(): void
    {
        Menu::factory()->create(['sppg_id' => $this->sppgA->id, 'nama_menu' => 'Nasi Ikan Lele', 'kelompok_sasaran' => 'siswa', 'tanggal_sajian' => '2026-11-01']);
        Menu::factory()->create(['sppg_id' => $this->sppgA->id, 'nama_menu' => 'Bubur Kacang Hijau', 'kelompok_sasaran' => 'balita', 'tanggal_sajian' => '2026-11-10']);
        Menu::factory()->count(3)->create(['sppg_id' => $this->sppgA->id, 'nama_menu' => 'Menu Lain', 'kelompok_sasaran' => 'siswa', 'tanggal_sajian' => '2026-12-01']);
        $this->login(Role::AhliGizi);

        $this->getJson('/api/v1/menu-gizi?q=lele')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/menu-gizi?kelompok_sasaran=balita')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nama_menu', 'Bubur Kacang Hijau');
        $this->getJson('/api/v1/menu-gizi?from=2026-11-05&to=2026-11-30')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/menu-gizi?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 5);
    }

    public function test_peringatan_gizi_dan_ringkasan(): void
    {
        StandarGizi::factory()->create(['sppg_id' => $this->sppgA->id, 'kelompok_sasaran' => 'siswa']);
        $beras = BahanBaku::factory()->create(['sppg_id' => $this->sppgA->id, 'nama' => 'Beras', 'satuan' => 'kg']);
        $this->login(Role::AhliGizi);

        $res = $this->postJson('/api/v1/menu-gizi', $this->payload(['protein_g' => 15, 'lemak_g' => 40, 'bahan' => [['bahan_baku_id' => $beras->id, 'gram_per_porsi' => 100]]]))
            ->assertCreated();
        $res->assertJsonCount(2, 'data.peringatan_gizi');
        $kolom = collect($res->json('data.peringatan_gizi'))->keyBy('kolom');
        $this->assertSame('kurang', $kolom['protein_g']['status']);
        $this->assertSame('lebih', $kolom['lemak_g']['status']);
        $this->assertStringContainsString('di bawah standar minimum 18 g', $kolom['protein_g']['pesan']);

        $id = $res->json('data.id');
        $this->getJson("/api/v1/menu-gizi/{$id}/ringkasan-gizi")->assertOk()
            ->assertJsonPath('data.sesuai_standar', false)
            ->assertJsonPath('data.standar_tersedia', true)
            ->assertJsonPath('data.per_porsi.protein_g', 15)
            ->assertJsonPath('data.total_gram_per_porsi', 100)
            ->assertJsonPath('data.standar.kelompok_sasaran', 'siswa')
            ->assertJsonCount(2, 'data.peringatan');

        $sesuai = Menu::factory()->gizi(600, 20, 18, 90, 8)->create(['sppg_id' => $this->sppgA->id, 'kelompok_sasaran' => 'siswa']);
        $this->getJson("/api/v1/menu-gizi/{$sesuai->id}/ringkasan-gizi")->assertOk()->assertJsonPath('data.sesuai_standar', true)->assertJsonPath('data.peringatan', []);

        $balita = Menu::factory()->create(['sppg_id' => $this->sppgA->id, 'kelompok_sasaran' => 'balita']);
        $this->getJson("/api/v1/menu-gizi/{$balita->id}/ringkasan-gizi")->assertOk()
            ->assertJsonPath('data.standar_tersedia', false)->assertJsonPath('data.sesuai_standar', null)->assertJsonPath('data.standar', null);
    }

    public function test_crud_standar_gizi_dan_restore(): void
    {
        $this->login(Role::AhliGizi);
        $body = ['kelompok_sasaran' => 'balita', 'energi_min' => 300, 'energi_max' => 450, 'protein_min' => 8, 'protein_max' => 15,
            'lemak_min' => 8, 'lemak_max' => 15, 'karbohidrat_min' => 40, 'karbohidrat_max' => 60, 'serat_min' => 3, 'serat_max' => 6];

        $id = $this->postJson('/api/v1/menu-gizi/standar-gizi', $body)->assertCreated()->assertJsonPath('data.energi_max', 450)->json('data.id');
        $this->postJson('/api/v1/menu-gizi/standar-gizi', $body)->assertUnprocessable()->assertJsonValidationErrors(['kelompok_sasaran']);
        $this->getJson("/api/v1/menu-gizi/standar-gizi/{$id}")->assertOk();
        $this->patchJson("/api/v1/menu-gizi/standar-gizi/{$id}", ['energi_max' => 500])->assertOk()->assertJsonPath('data.energi_max', 500);
        $this->patchJson("/api/v1/menu-gizi/standar-gizi/{$id}", ['energi_max' => 100])->assertUnprocessable()->assertJsonValidationErrors(['energi_max']);
        $this->deleteJson("/api/v1/menu-gizi/standar-gizi/{$id}")->assertNoContent();
        $this->assertSoftDeleted('standar_gizis', ['id' => $id]);
        $this->assertTrue(AuditLog::query()->where('auditable_type', (new StandarGizi)->getMorphClass())->where('event', 'deleted')->exists());

        // Dibuat ulang untuk kelompok yang sama: memulihkan baris lama.
        $this->postJson('/api/v1/menu-gizi/standar-gizi', array_merge($body, ['energi_max' => 480]))->assertCreated()->assertJsonPath('data.id', $id)->assertJsonPath('data.energi_max', 480);
        $this->assertSame(1, StandarGizi::withTrashed()->count());

        $this->postJson('/api/v1/menu-gizi/standar-gizi', [])->assertUnprocessable()->assertJsonValidationErrors(['kelompok_sasaran', 'energi_min']);
    }

    public function test_hapus_menu_dipakai_jadwal_aktif_ditolak(): void
    {
        $menu = Menu::factory()->create(['sppg_id' => $this->sppgA->id]);
        $jadwal = JadwalProduksi::factory()->create(['sppg_id' => $this->sppgA->id, 'menu_id' => $menu->id]);
        $this->login(Role::AhliGizi);

        $this->deleteJson("/api/v1/menu-gizi/{$menu->id}")->assertUnprocessable()->assertJsonValidationErrors(['menu']);
        $jadwal->update(['status' => 'selesai']);
        $this->deleteJson("/api/v1/menu-gizi/{$menu->id}")->assertNoContent();
    }

    public function test_draf_ai_dikonfirmasi(): void
    {
        $user = $this->user(Role::AhliGizi);
        $draf = AiDraft::factory()->create(['sppg_id' => $this->sppgA->id, 'user_id' => $user->id, 'module' => 'menu-gizi']);
        $salahModul = AiDraft::factory()->create(['sppg_id' => $this->sppgA->id, 'user_id' => $user->id, 'module' => 'pengeluaran']);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/menu-gizi', $this->payload(['ai_draft_id' => $salahModul->uuid]))->assertUnprocessable()->assertJsonValidationErrors(['ai_draft_id']);
        $this->postJson('/api/v1/menu-gizi', $this->payload(['ai_draft_id' => $draf->uuid]))->assertCreated();
        $this->assertSame(StatusAiDraft::Confirmed, $draf->fresh()->status);
    }
}
