<?php

namespace Tests\Feature\Persetujuan;

use App\Enums\Role;
use App\Models\Menu;
use App\Models\PengajuanMenu;

/** Keputusan investor: tiga hasil, catatan, hak, IDOR lintas investor, balapan/versi (409). */
class KeputusanMenuTest extends PersetujuanTestCase
{
    private function url(int $id): string
    {
        return "/api/v1/persetujuan-menu/{$id}/keputusan";
    }

    public function test_investor_menyetujui_menu_dan_audit_tercatat(): void
    {
        $menu = $this->menuSiapAjukan();
        $id = $this->ajukan($menu);

        $this->sebagai($this->investorAB, $this->sppgA)
            ->postJson($this->url($id), ['keputusan' => 'disetujui', 'versi_menu' => 1])
            ->assertOk()
            ->assertJsonPath('data.status', 'disetujui')
            ->assertJsonPath('data.diputuskan_oleh', $this->investorAB->name);

        $this->assertSame('disetujui', $this->menuSegar($menu)->status_persetujuan->value);
        $this->assertSame(1, $this->jumlahAudit('updated', PengajuanMenu::class, $id));
        $this->assertSame($this->sppgA, (int) \DB::table('audit_logs')->where('event', 'updated')->where('auditable_id', $id)->value('sppg_id'));
    }

    public function test_ditolak_dan_perlu_revisi_wajib_catatan_min_lima(): void
    {
        $menu = $this->menuSiapAjukan();
        $id = $this->ajukan($menu);
        $this->sebagai($this->investorAB, $this->sppgA);

        foreach (['ditolak', 'perlu_revisi'] as $k) {
            $this->postJson($this->url($id), ['keputusan' => $k, 'versi_menu' => 1])->assertUnprocessable()->assertJsonValidationErrors(['catatan']);
            $this->postJson($this->url($id), ['keputusan' => $k, 'catatan' => 'abcd', 'versi_menu' => 1])->assertUnprocessable()->assertJsonValidationErrors(['catatan']);
            $this->postJson($this->url($id), ['keputusan' => $k, 'catatan' => str_repeat('a', 1001), 'versi_menu' => 1])->assertUnprocessable();
        }

        $this->postJson($this->url($id), ['keputusan' => 'dibatalkan', 'versi_menu' => 1])->assertUnprocessable()->assertJsonValidationErrors(['keputusan']);
        $this->postJson($this->url($id), ['keputusan' => 'ditolak', 'catatan' => 'Biaya terlalu tinggi', 'versi_menu' => 1])
            ->assertOk()->assertJsonPath('data.status', 'ditolak')->assertJsonPath('data.catatan_keputusan', 'Biaya terlalu tinggi');
        $this->assertSame('ditolak', $this->menuSegar($menu)->status_persetujuan->value);
    }

    public function test_perlu_revisi_dan_versi_menu_wajib(): void
    {
        $menu = $this->menuSiapAjukan();
        $id = $this->ajukan($menu);
        $this->sebagai($this->investorAB, $this->sppgA);

        $this->postJson($this->url($id), ['keputusan' => 'disetujui'])->assertUnprocessable()->assertJsonValidationErrors(['versi_menu']);
        $this->postJson($this->url($id), ['keputusan' => 'perlu_revisi', 'catatan' => 'Tambah sayur', 'versi_menu' => 1])
            ->assertOk()->assertJsonPath('data.status', 'perlu_revisi');
        $this->assertSame('perlu_revisi', $this->menuSegar($menu)->status_persetujuan->value);
    }

    public function test_peran_selain_investor_tidak_boleh_memutuskan(): void
    {
        $menu = $this->menuSiapAjukan();
        $id = $this->ajukan($menu);
        $body = ['keputusan' => 'disetujui', 'versi_menu' => 1];

        foreach ([$this->admin, $this->kepalaA, $this->ahliGiziA, $this->akuntanA, $this->stafGudangA, $this->relawanA] as $user) {
            $this->sebagai($user)->postJson($this->url($id), $body)->assertForbidden();
        }

        $this->assertSame('diajukan', PengajuanMenu::query()->withoutGlobalScopes()->findOrFail($id)->status->value);
        $this->assertSame('diajukan', $this->menuSegar($menu)->status_persetujuan->value);
    }

    public function test_investor_lain_atau_tanpa_pivot_tidak_boleh_memutuskan(): void
    {
        $menu = $this->menuSiapAjukan();
        $id = $this->ajukan($menu);
        $body = ['keputusan' => 'disetujui', 'versi_menu' => 1];

        // Investor C (hanya SPPG C): header SPPG A ditolak middleware; header C -> pengajuan tidak terlihat.
        $this->sebagai($this->investorC, $this->sppgA)->postJson($this->url($id), $body)->assertForbidden();
        $this->sebagai($this->investorC, $this->sppgC)->postJson($this->url($id), $body)->assertNotFound();
        $this->sebagai($this->investorC)->postJson($this->url($id), $body)->assertNotFound();

        $tanpaPivot = $this->investor([]);
        $this->sebagai($tanpaPivot)->postJson($this->url($id), $body)->assertForbidden();
        $this->sebagai($tanpaPivot, $this->sppgA)->postJson($this->url($id), $body)->assertForbidden();

        // Investor AB dengan header SPPG B: pengajuan SPPG A tidak terlihat.
        $this->sebagai($this->investorAB, $this->sppgB)->postJson($this->url($id), $body)->assertNotFound();
        $this->assertSame('diajukan', PengajuanMenu::query()->withoutGlobalScopes()->findOrFail($id)->status->value);
    }

    public function test_sudah_diputuskan_409(): void
    {
        $menu = $this->menuSiapAjukan();
        $id = $this->ajukan($menu);
        $kedua = $this->investor([$this->sppgA]);

        $this->sebagai($this->investorAB, $this->sppgA)->postJson($this->url($id), ['keputusan' => 'disetujui', 'versi_menu' => 1])->assertOk();
        $this->postJson($this->url($id), ['keputusan' => 'ditolak', 'catatan' => 'Terlambat memutuskan', 'versi_menu' => 1])
            ->assertStatus(409)->assertJsonPath('alasan', 'pengajuan_sudah_diputuskan');
        $this->sebagai($kedua, $this->sppgA)->postJson($this->url($id), ['keputusan' => 'ditolak', 'catatan' => 'Investor kedua', 'versi_menu' => 1])
            ->assertStatus(409)->assertJsonPath('alasan', 'pengajuan_sudah_diputuskan');

        $this->assertSame('disetujui', $this->menuSegar($menu)->status_persetujuan->value);
    }

    public function test_menu_diubah_setelah_diajukan_membatalkan_pengajuan_dan_keputusan_409(): void
    {
        $menu = $this->menuSiapAjukan();
        $id = $this->ajukan($menu);

        $this->sebagai($this->ahliGiziA)->patchJson("/api/v1/menu-gizi/{$menu->id}", ['catatan' => 'Ganti lauk'])->assertOk();

        $this->sebagai($this->investorAB, $this->sppgA)
            ->postJson($this->url($id), ['keputusan' => 'disetujui', 'versi_menu' => 1])
            ->assertStatus(409)->assertJsonPath('alasan', 'pengajuan_sudah_diputuskan');
        $this->assertSame('draf', $this->menuSegar($menu)->status_persetujuan->value);
    }

    public function test_pengajuan_masih_diajukan_tetapi_menu_berubah_atau_versi_klien_basi_409(): void
    {
        $menu = $this->menuSiapAjukan();
        $id = $this->ajukan($menu);
        $this->sebagai($this->investorAB, $this->sppgA);

        // Versi dari klien basi (tidak sama dengan versi server/pengajuan).
        $this->postJson($this->url($id), ['keputusan' => 'disetujui', 'versi_menu' => 3])
            ->assertStatus(409)->assertJsonPath('alasan', 'versi_menu_tidak_cocok');

        // Menu diubah di luar jalur normal (status/versi tidak sinkron dengan pengajuan): ditolak.
        Menu::withoutGlobalScopes()->whereKey($menu->id)->update(['versi' => 2]);
        $this->postJson($this->url($id), ['keputusan' => 'disetujui', 'versi_menu' => 2])
            ->assertStatus(409)->assertJsonPath('alasan', 'menu_berubah_setelah_diajukan');
        Menu::withoutGlobalScopes()->whereKey($menu->id)->update(['versi' => 1, 'status_persetujuan' => 'draf']);
        $this->postJson($this->url($id), ['keputusan' => 'disetujui', 'versi_menu' => 1])
            ->assertStatus(409)->assertJsonPath('alasan', 'menu_berubah_setelah_diajukan');

        $this->assertSame('diajukan', PengajuanMenu::query()->withoutGlobalScopes()->findOrFail($id)->status->value);
    }

    public function test_investor_membaca_daftar_dan_detail_hanya_sppg_aktif(): void
    {
        $menuA = $this->menuSiapAjukan();
        $idA = $this->ajukan($menuA);
        $menuC = $this->menuSiapAjukan($this->sppgC);
        $ahliC = $this->userOf(Role::AhliGizi, $this->sppgC);
        $idC = (int) $this->sebagai($ahliC)->postJson("/api/v1/menu-gizi/{$menuC->id}/ajukan", ['versi' => 1])->assertCreated()->json('data.id');

        $res = $this->sebagai($this->investorAB, $this->sppgA)->getJson('/api/v1/persetujuan-menu?status=diajukan')->assertOk();
        $this->assertSame([$idA], array_column($res->json('data'), 'id'));
        $this->assertArrayNotHasKey('rincian', $res->json('data.0'));
        $this->getJson("/api/v1/persetujuan-menu/{$idA}")->assertOk()->assertJsonPath('data.kedaluwarsa', false)->assertJsonStructure(['data' => ['rincian' => ['bahan']]]);
        $this->getJson("/api/v1/persetujuan-menu/{$idC}")->assertNotFound();
        $this->getJson("/api/v1/persetujuan-menu?menu_id={$menuC->id}")->assertOk()->assertJsonCount(0, 'data');
        $this->sebagai($this->investorC, $this->sppgC)->getJson("/api/v1/persetujuan-menu/{$idA}")->assertNotFound();
        $this->sebagai($this->investorAB)->getJson('/api/v1/persetujuan-menu')->assertStatus(409);
    }

    public function test_tidak_ada_rute_ubah_atau_hapus_pengajuan(): void
    {
        $id = $this->ajukan($this->menuSiapAjukan());

        $this->sebagai($this->admin);
        $this->putJson("/api/v1/persetujuan-menu/{$id}", ['status' => 'disetujui'])->assertStatus(405);
        $this->patchJson("/api/v1/persetujuan-menu/{$id}", ['status' => 'disetujui'])->assertStatus(405);
        $this->deleteJson("/api/v1/persetujuan-menu/{$id}")->assertStatus(405);
        $this->postJson('/api/v1/persetujuan-menu', [])->assertStatus(405);
    }
}
