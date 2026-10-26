<?php

namespace Tests\Feature\Pembelian;

use App\Models\JadwalProduksi;
use App\Models\Menu;
use App\Models\Scopes\SppgScope;

/** Perbaikan S2 (R-06b-2): jadwal produksi lama ditandai saat menu kembali ke draf dan tidak dapat dimasak. */
class JadwalMenuBerubahTest extends PembelianTestCase
{
    private function jadwalDariMenuDisetujui(): JadwalProduksi
    {
        $this->forgetActor();
        $menu = Menu::factory()->create(['sppg_id' => $this->sppgA, 'status_persetujuan' => 'disetujui', 'nama_menu' => 'Nasi Ayam Uji']);

        return JadwalProduksi::factory()->create([
            'sppg_id' => $this->sppgA, 'menu_id' => $menu->id, 'target_porsi' => 100, 'tanggal_produksi' => now()->addDay()->toDateString(),
        ]);
    }

    private function statusMenu(int $id): string
    {
        return Menu::withoutGlobalScope(SppgScope::class)->findOrFail($id)->status_persetujuan->value;
    }

    public function test_menu_diubah_kembali_ke_draf_menandai_jadwal_lama_tanpa_menyentuh_baris_jadwal(): void
    {
        $jadwal = $this->jadwalDariMenuDisetujui();
        $diperbarui = JadwalProduksi::withoutGlobalScope(SppgScope::class)->findOrFail($jadwal->id)->updated_at;

        $this->sebagai($this->kepalaA)->getJson("/api/v1/jadwal/{$jadwal->id}")->assertOk()->assertJsonPath('data.menu_belum_disetujui', false);

        $this->sebagai($this->ahliGiziA)->patchJson("/api/v1/menu-gizi/{$jadwal->menu_id}", ['nama_menu' => 'Nasi Ayam Uji Revisi'])->assertOk();
        $this->assertSame('draf', $this->statusMenu($jadwal->menu_id));

        $this->sebagai($this->kepalaA)->getJson("/api/v1/jadwal/{$jadwal->id}")->assertOk()
            ->assertJsonPath('data.menu_belum_disetujui', true)->assertJsonPath('data.menu.status_persetujuan', 'draf');
        $this->getJson('/api/v1/jadwal?menu_belum_disetujui=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $jadwal->id);
        $this->getJson('/api/v1/jadwal?menu_belum_disetujui=0')->assertOk()->assertJsonPath('meta.total', 1);

        // Tidak ada penulisan ke baris jadwal (menghindari urutan kunci terbalik).
        $this->assertEquals($diperbarui, JadwalProduksi::withoutGlobalScope(SppgScope::class)->findOrFail($jadwal->id)->updated_at);
    }

    public function test_jadwal_dengan_menu_belum_disetujui_tidak_dapat_dimasak_422(): void
    {
        $jadwal = $this->jadwalDariMenuDisetujui();
        Menu::withoutGlobalScopes()->whereKey($jadwal->menu_id)->update(['status_persetujuan' => 'diajukan']);

        $this->sebagai($this->kepalaA)->patchJson("/api/v1/jadwal/{$jadwal->id}", ['status' => 'dimasak'])
            ->assertUnprocessable()->assertJsonValidationErrors('status')
            ->assertJsonPath('errors.status.0', 'Menu jadwal ini belum disetujui investor; ajukan ulang menu sebelum memasak.');
        $this->assertSame('direncanakan', JadwalProduksi::withoutGlobalScope(SppgScope::class)->findOrFail($jadwal->id)->status->value);

        foreach (['ditolak', 'perlu_revisi', 'draf'] as $status) {
            Menu::withoutGlobalScopes()->whereKey($jadwal->menu_id)->update(['status_persetujuan' => $status]);
            $this->patchJson("/api/v1/jadwal/{$jadwal->id}", ['status' => 'dimasak'])->assertUnprocessable()->assertJsonValidationErrors('status');
        }

        // Dibatalkan tetap boleh walau menu belum disetujui.
        $this->patchJson("/api/v1/jadwal/{$jadwal->id}", ['status' => 'dibatalkan'])->assertOk()->assertJsonPath('data.status', 'dibatalkan');
    }

    public function test_menu_disetujui_kembali_menghapus_penanda_dan_jadwal_dapat_dimasak_lalu_selesai(): void
    {
        $jadwal = $this->jadwalDariMenuDisetujui();
        Menu::withoutGlobalScopes()->whereKey($jadwal->menu_id)->update(['status_persetujuan' => 'draf']);
        $this->sebagai($this->kepalaA)->patchJson("/api/v1/jadwal/{$jadwal->id}", ['status' => 'dimasak'])->assertUnprocessable();

        Menu::withoutGlobalScopes()->whereKey($jadwal->menu_id)->update(['status_persetujuan' => 'disetujui']);
        $this->getJson("/api/v1/jadwal/{$jadwal->id}")->assertJsonPath('data.menu_belum_disetujui', false);
        $this->patchJson("/api/v1/jadwal/{$jadwal->id}", ['status' => 'dimasak'])->assertOk()->assertJsonPath('data.status', 'dimasak');

        // Sudah dimasak: menu kembali ke draf tidak menghalangi penyelesaian (makanan sudah diproses).
        Menu::withoutGlobalScopes()->whereKey($jadwal->menu_id)->update(['status_persetujuan' => 'draf']);
        $this->patchJson("/api/v1/jadwal/{$jadwal->id}", ['status' => 'selesai'])->assertOk()->assertJsonPath('data.status', 'selesai');
    }

    public function test_ahli_gizi_dan_peran_lain_tanpa_hak_jadwal_tetap_ditolak_dan_sppg_lain_tidak_terlihat(): void
    {
        $jadwal = $this->jadwalDariMenuDisetujui();
        Menu::withoutGlobalScopes()->whereKey($jadwal->menu_id)->update(['status_persetujuan' => 'draf']);

        $this->sebagai($this->akuntanA)->patchJson("/api/v1/jadwal/{$jadwal->id}", ['status' => 'dimasak'])->assertForbidden();
        $this->sebagai($this->kepalaB)->patchJson("/api/v1/jadwal/{$jadwal->id}", ['status' => 'dimasak'])->assertNotFound();
        $this->sebagai($this->kepalaB)->getJson('/api/v1/jadwal?menu_belum_disetujui=1')->assertOk()->assertJsonPath('meta.total', 0);
    }
}
