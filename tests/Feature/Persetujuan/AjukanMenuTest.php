<?php

namespace Tests\Feature\Persetujuan;

use App\Enums\Role;
use App\Models\Menu;
use App\Models\PengajuanMenu;
use App\Models\Scopes\SppgScope;

/** Ajukan menu ke investor: snapshot server, versi, status, RBAC, IDOR. */
class AjukanMenuTest extends PersetujuanTestCase
{
    public function test_ahli_gizi_mengajukan_snapshot_dihitung_server(): void
    {
        $menu = $this->menuSiapAjukan();

        $res = $this->sebagai($this->ahliGiziA)
            ->postJson("/api/v1/menu-gizi/{$menu->id}/ajukan", ['versi' => 1, 'catatan_pengaju' => 'Mohon ditinjau'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'diajukan')
            ->assertJsonPath('data.nomor', 1)
            ->assertJsonPath('data.menu_versi', 1)
            ->assertJsonPath('data.nama_menu', 'Nasi Telur Uji')
            ->assertJsonPath('data.jumlah_porsi', 10)
            ->assertJsonPath('data.sumber_porsi', 'penerima_manfaat_kelompok');

        $this->assertEquals(6000, $res->json('data.biaya_per_porsi'));
        $this->assertEquals(60000, $res->json('data.total_biaya'));
        $this->assertCount(2, $res->json('data.rincian.bahan'));
        $this->assertSame('diajukan', $this->menuSegar($menu)->status_persetujuan->value);
        $this->assertSame(1, $this->jumlahAudit('created', PengajuanMenu::class, (int) $res->json('data.id')));

        $this->getJson("/api/v1/menu-gizi/{$menu->id}")->assertOk()
            ->assertJsonPath('data.status_persetujuan', 'diajukan')
            ->assertJsonPath('data.versi', 1)
            ->assertJsonPath('data.pengajuan_terakhir.status', 'diajukan');
    }

    public function test_field_biaya_dari_klien_diabaikan(): void
    {
        $menu = $this->menuSiapAjukan();

        $res = $this->sebagai($this->ahliGiziA)->postJson("/api/v1/menu-gizi/{$menu->id}/ajukan", [
            'versi' => 1, 'biaya_per_porsi' => 1, 'total_biaya' => 1, 'jumlah_porsi' => 999999, 'harga' => 1,
            'rincian' => ['bahan' => []], 'status' => 'disetujui', 'sppg_id' => $this->sppgB,
        ])->assertCreated();

        $this->assertEquals(6000, $res->json('data.biaya_per_porsi'));
        $this->assertSame(10, $res->json('data.jumlah_porsi'));
        $this->assertSame('diajukan', $res->json('data.status'));
        $this->assertSame($this->sppgA, $res->json('data.sppg_id'));
        $this->assertCount(2, $res->json('data.rincian.bahan'));
    }

    public function test_snapshot_tidak_berubah_saat_harga_pemasok_berubah(): void
    {
        $menu = $this->menuSiapAjukan();
        $id = $this->ajukan($menu);

        $pemasok = $this->pemasok($this->sppgA);
        $bahan = $menu->bahanBakus()->withoutGlobalScope(SppgScope::class)->first();
        $this->harga($pemasok, $bahan, 999999, now()->toDateString());
        $bahan->forceFill(['pemasok_id' => $pemasok->id])->save();

        $this->sebagai($this->kepalaA)->getJson("/api/v1/persetujuan-menu/{$id}")->assertOk()
            ->assertJsonPath('data.biaya_per_porsi', 6000)
            ->assertJsonPath('data.rincian.bahan.0.harga_per_satuan', 20000);
    }

    public function test_versi_beda_409_status_salah_409_validasi_422(): void
    {
        $menu = $this->menuSiapAjukan();
        $this->sebagai($this->ahliGiziA);

        $this->postJson("/api/v1/menu-gizi/{$menu->id}/ajukan", ['versi' => 7])->assertStatus(409)->assertJsonPath('alasan', 'versi_menu_tidak_cocok');
        $this->postJson("/api/v1/menu-gizi/{$menu->id}/ajukan", [])->assertUnprocessable()->assertJsonValidationErrors(['versi']);

        $this->ajukan($menu);
        $disetujui = $this->menuSiapAjukan(null, 'disetujui');
        $this->sebagai($this->ahliGiziA);
        $this->postJson("/api/v1/menu-gizi/{$menu->id}/ajukan", ['versi' => 1])->assertStatus(409)->assertJsonPath('alasan', 'status_menu_tidak_valid');
        $this->postJson("/api/v1/menu-gizi/{$disetujui->id}/ajukan", ['versi' => 1])->assertStatus(409)->assertJsonPath('alasan', 'status_menu_tidak_valid');
    }

    public function test_ajukan_ulang_setelah_ditolak_membuat_nomor_baru(): void
    {
        $menu = $this->menuSiapAjukan();
        $id = $this->ajukan($menu);
        $this->sebagai($this->investorAB, $this->sppgA)
            ->postJson("/api/v1/persetujuan-menu/{$id}/keputusan", ['keputusan' => 'perlu_revisi', 'catatan' => 'Kurangi telur', 'versi_menu' => 1])->assertOk();

        $this->assertSame('perlu_revisi', $this->menuSegar($menu)->status_persetujuan->value);
        $this->sebagai($this->ahliGiziA)->postJson("/api/v1/menu-gizi/{$menu->id}/ajukan", ['versi' => 1])->assertCreated()->assertJsonPath('data.nomor', 2);
    }

    public function test_hanya_ahli_gizi_yang_boleh_mengajukan(): void
    {
        $menu = $this->menuSiapAjukan();

        foreach ([$this->admin, $this->kepalaA, $this->akuntanA, $this->stafGudangA, $this->relawanA] as $user) {
            $this->sebagai($user)->postJson("/api/v1/menu-gizi/{$menu->id}/ajukan", ['versi' => 1])->assertForbidden();
        }

        $this->sebagai($this->investorAB, $this->sppgA)->postJson("/api/v1/menu-gizi/{$menu->id}/ajukan", ['versi' => 1])->assertForbidden();
        $this->assertSame('draf', $this->menuSegar($menu)->status_persetujuan->value);
        $this->assertSame(0, PengajuanMenu::query()->withoutGlobalScopes()->count());
    }

    public function test_ahli_gizi_sppg_lain_tidak_bisa_mengajukan_menu_sppg_ini(): void
    {
        $menu = $this->menuSiapAjukan();
        $ahliB = $this->userOf(Role::AhliGizi, $this->sppgB);

        $this->sebagai($ahliB)->postJson("/api/v1/menu-gizi/{$menu->id}/ajukan", ['versi' => 1])->assertNotFound();
        $this->getJson("/api/v1/menu-gizi/{$menu->id}/rincian-biaya")->assertNotFound();
        $this->assertSame('draf', $this->menuSegar($menu)->status_persetujuan->value);
    }

    public function test_ubah_menu_naikkan_versi_reset_draf_dan_batalkan_pengajuan(): void
    {
        $menu = $this->menuSiapAjukan();
        $id = $this->ajukan($menu);

        $this->sebagai($this->ahliGiziA)->patchJson("/api/v1/menu-gizi/{$menu->id}", ['nama_menu' => 'Nasi Telur Revisi'])
            ->assertOk()
            ->assertJsonPath('data.versi', 2)
            ->assertJsonPath('data.status_persetujuan', 'draf');

        $pengajuan = PengajuanMenu::query()->withoutGlobalScopes()->findOrFail($id);
        $this->assertSame('dibatalkan', $pengajuan->status->value);
        $this->assertNull($pengajuan->diputuskan_oleh);
        $this->assertStringContainsString('diubah', (string) $pengajuan->catatan_keputusan);
    }

    public function test_ubah_komposisi_dan_menu_disetujui_kembali_ke_draf(): void
    {
        $menu = $this->menuSiapAjukan(null, 'disetujui');
        $bahan = $menu->bahanBakus()->withoutGlobalScope(SppgScope::class)->first();

        $this->sebagai($this->ahliGiziA)->patchJson("/api/v1/menu-gizi/{$menu->id}", ['bahan' => [['bahan_baku_id' => $bahan->id, 'gram_per_porsi' => 80]]])
            ->assertOk()
            ->assertJsonPath('data.versi', 2)
            ->assertJsonPath('data.status_persetujuan', 'draf');
    }

    public function test_hapus_menu_membatalkan_pengajuan_menunggu(): void
    {
        $menu = $this->menuSiapAjukan();
        $id = $this->ajukan($menu);

        $this->sebagai($this->ahliGiziA)->deleteJson("/api/v1/menu-gizi/{$menu->id}")->assertNoContent();

        $this->assertSame('dibatalkan', PengajuanMenu::query()->withoutGlobalScopes()->findOrFail($id)->status->value);
        $this->assertTrue(Menu::withoutGlobalScopes()->withTrashed()->findOrFail($menu->id)->trashed());
    }

    public function test_menu_baru_default_draf_versi_satu(): void
    {
        $this->sebagai($this->ahliGiziA)
            ->postJson('/api/v1/menu-gizi', ['nama_menu' => 'Menu Baru Uji', 'kelompok_sasaran' => 'siswa', 'status_persetujuan' => 'disetujui', 'versi' => 9])
            ->assertCreated()
            ->assertJsonPath('data.status_persetujuan', 'draf')
            ->assertJsonPath('data.versi', 1);
    }
}
