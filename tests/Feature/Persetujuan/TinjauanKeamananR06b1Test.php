<?php

namespace Tests\Feature\Persetujuan;

use App\Enums\Role;
use App\Models\JadwalProduksi;
use App\Models\Menu;
use App\Models\PenerimaManfaat;
use App\Models\PengajuanMenu;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;

/** Perbaikan tinjauan keamanan R-06b-1 (S1, R1, R2, R3, R4, I2). Data fiktif. */
class TinjauanKeamananR06b1Test extends PersetujuanTestCase
{
    private const RAHASIA = 'Catatan rahasia investor 7731';

    // ------------------------------------------------------------------ S1a rincian biaya

    public function test_rincian_biaya_hanya_untuk_peran_dengan_hak_harga(): void
    {
        $menu = $this->menuSiapAjukan();
        $url = "/api/v1/menu-gizi/{$menu->id}/rincian-biaya";

        foreach ([$this->ahliGiziA, $this->kepalaA, $this->admin, $this->stafGudangA] as $user) {
            $this->sebagai($user)->getJson($url)->assertOk()->assertJsonPath('data.menu_id', $menu->id);
        }

        // Relawan: boleh baca menu, tetapi tanpa hak harga pemasok.
        $this->sebagai($this->relawanA)->getJson("/api/v1/menu-gizi/{$menu->id}")->assertOk();
        $this->sebagai($this->relawanA)->getJson($url)->assertForbidden();
        // Akuntan (pemasok R, tanpa menu-gizi) dan investor (tanpa menu-gizi) tetap tertutup.
        $this->sebagai($this->akuntanA)->getJson($url)->assertForbidden();
        $this->sebagai($this->investorAB, $this->sppgA)->getJson($url)->assertForbidden();
    }

    public function test_rincian_biaya_lintas_sppg_tertutup(): void
    {
        $menu = $this->menuSiapAjukan();

        $this->assertContains(
            $this->sebagai($this->userOf(Role::AhliGizi, $this->sppgB))->getJson("/api/v1/menu-gizi/{$menu->id}/rincian-biaya")->status(),
            [403, 404],
        );
    }

    // ------------------------------------------------------------------ S1b pengajuan_terakhir

    public function test_pengajuan_terakhir_hanya_terlihat_peran_persetujuan_menu(): void
    {
        $menu = $this->menuSiapAjukan();
        $id = $this->ajukan($menu);
        $this->sebagai($this->investorAB, $this->sppgA)
            ->postJson("/api/v1/persetujuan-menu/{$id}/keputusan", ['keputusan' => 'perlu_revisi', 'catatan' => self::RAHASIA, 'versi_menu' => 1])
            ->assertOk();

        foreach ([$this->stafGudangA, $this->relawanA] as $user) {
            $detail = $this->sebagai($user)->getJson("/api/v1/menu-gizi/{$menu->id}")->assertOk();
            $detail->assertJsonMissingPath('data.pengajuan_terakhir');
            $this->assertStringNotContainsString(self::RAHASIA, $detail->getContent());
            $this->assertStringNotContainsString('biaya_per_porsi', $detail->getContent());

            $daftar = $this->getJson('/api/v1/menu-gizi')->assertOk();
            $this->assertStringNotContainsString(self::RAHASIA, $daftar->getContent());
            $this->assertStringNotContainsString('total_biaya', $daftar->getContent());
        }

        foreach ([$this->ahliGiziA, $this->kepalaA, $this->admin] as $user) {
            $this->sebagai($user)->getJson("/api/v1/menu-gizi/{$menu->id}")->assertOk()
                ->assertJsonPath('data.pengajuan_terakhir.catatan_keputusan', self::RAHASIA)
                ->assertJsonPath('data.pengajuan_terakhir.total_biaya', 60000);
            $this->getJson('/api/v1/menu-gizi')->assertOk()->assertJsonPath('data.0.pengajuan_terakhir.status', 'perlu_revisi');
        }

        // Investor tidak membaca menu-gizi, tetapi tetap melihat harga dan catatan lewat modul persetujuan.
        $this->sebagai($this->investorAB, $this->sppgA)->getJson("/api/v1/persetujuan-menu/{$id}")->assertOk()
            ->assertJsonPath('data.total_biaya', 60000)
            ->assertJsonPath('data.catatan_keputusan', self::RAHASIA);
    }

    // ------------------------------------------------------------------ R1 overflow decimal

    private function menuBiayaRaksasa(float|int $harga, float|int $gram, ?int $targetPorsi = null): Menu
    {
        $pemasok = $this->pemasok($this->sppgA, 'koperasi');
        $bahan = $this->bahan($this->sppgA, 'kg', $pemasok->id, 'Bahan Mahal Uji');
        $this->harga($pemasok, $bahan, $harga);
        $menu = Menu::factory()->create(['sppg_id' => $this->sppgA, 'status_persetujuan' => 'draf']);
        $menu->bahanBakus()->attach([$bahan->id => ['gram_per_porsi' => $gram]]);
        PenerimaManfaat::factory()->count(10)->create(['sppg_id' => $this->sppgA, 'kelompok_sasaran' => 'siswa', 'is_active' => true]);

        if ($targetPorsi !== null) {
            JadwalProduksi::factory()->create(['sppg_id' => $this->sppgA, 'menu_id' => $menu->id, 'tanggal_produksi' => now()->addDay()->toDateString(), 'target_porsi' => $targetPorsi]);
        }

        return $menu->refresh();
    }

    public function test_biaya_per_porsi_melebihi_kolom_ditolak_422_bukan_500(): void
    {
        // 99.999.999 g x 999.999.999.999 / kg = ~1e20 per porsi.
        $menu = $this->menuBiayaRaksasa(999999999999, 99999999);

        $this->assertAlasan($this->sebagai($this->ahliGiziA)->postJson("/api/v1/menu-gizi/{$menu->id}/ajukan", ['versi' => 1]), 422, 'biaya_melebihi_batas')
            ->assertJsonValidationErrors(['bahan']);
        $this->assertSame('draf', $this->menuSegar($menu)->status_persetujuan->value);
        $this->assertSame(0, PengajuanMenu::query()->withoutGlobalScopes()->count());
    }

    public function test_total_biaya_melebihi_kolom_ditolak_422_dan_batas_sah_diterima(): void
    {
        // 9e11 per porsi (di bawah batas) x 500 porsi jadwal = 4,5e14 > batas total 1e14.
        $menu = $this->menuBiayaRaksasa(900000000000, 1000, 500);
        $this->assertAlasan($this->sebagai($this->ahliGiziA)->postJson("/api/v1/menu-gizi/{$menu->id}/ajukan", ['versi' => 1]), 422, 'biaya_melebihi_batas');
        $this->assertSame(0, PengajuanMenu::query()->withoutGlobalScopes()->count());

        // Per porsi 9e11 x 20 porsi = 1,8e13: muat di kolom total, tersimpan utuh.
        $ok = $this->menuBiayaRaksasa(900000000000, 1000);
        $this->sebagai($this->ahliGiziA)->postJson("/api/v1/menu-gizi/{$ok->id}/ajukan", ['versi' => 1])
            ->assertCreated()
            ->assertJsonPath('data.biaya_per_porsi', 900000000000)
            ->assertJsonPath('data.jumlah_porsi', 20)   // 2 x 10 penerima aktif SPPG A dari kedua menu uji
            ->assertJsonPath('data.total_biaya', 18000000000000);
    }

    // ------------------------------------------------------------------ I2 porsi nol

    public function test_ajukan_dengan_jumlah_porsi_nol_ditolak_422(): void
    {
        $menu = $this->menuSiapAjukan();
        $this->forgetActor();
        PenerimaManfaat::query()->withoutGlobalScopes()->update(['is_active' => false]);

        $this->assertAlasan($this->sebagai($this->ahliGiziA)->postJson("/api/v1/menu-gizi/{$menu->id}/ajukan", ['versi' => 1]), 422, 'jumlah_porsi_nol')
            ->assertJsonValidationErrors(['jumlah_porsi']);
        $this->assertSame('draf', $this->menuSegar($menu)->status_persetujuan->value);
        $this->assertSame(0, PengajuanMenu::query()->withoutGlobalScopes()->count());
    }

    // ------------------------------------------------------------------ R2 gram_per_satuan

    public function test_gram_per_satuan_minimal_0_001_dan_maksimal_3_desimal(): void
    {
        $pemasok = $this->pemasok($this->sppgA);
        $butir = $this->bahan($this->sppgA, 'butir');
        $this->sebagai($this->stafGudangA);
        $base = ['bahan_baku_id' => $butir->id, 'harga' => 2000, 'berlaku_mulai' => now()->toDateString()];
        $url = "/api/v1/pemasok/{$pemasok->id}/harga";

        foreach ([0, 0.0004, 0.0001, 12.3456, '1e-3', -1] as $buruk) {
            $this->postJson($url, $base + ['gram_per_satuan' => $buruk])
                ->assertUnprocessable()->assertJsonValidationErrors(['gram_per_satuan']);
        }

        $this->postJson($url, $base + ['gram_per_satuan' => 0.001])->assertCreated();
        $this->postJson($url, $base + ['gram_per_satuan' => 12.345])->assertCreated();
        $this->postJson($url, $base + ['gram_per_satuan' => 50])->assertCreated();
        $this->postJson($url, $base + ['gram_per_satuan' => 1000001])->assertUnprocessable();
    }

    // ------------------------------------------------------------------ R3 gate jadwal vs ubah menu

    public function test_jadwal_ditolak_setelah_menu_disetujui_diubah_kembali_ke_draf(): void
    {
        $menu = $this->menuSiapAjukan();
        $id = $this->ajukan($menu);
        $this->sebagai($this->investorAB, $this->sppgA)
            ->postJson("/api/v1/persetujuan-menu/{$id}/keputusan", ['keputusan' => 'disetujui', 'versi_menu' => 1])->assertOk();
        $body = ['menu_id' => $menu->id, 'tanggal_produksi' => now()->addDay()->toDateString(), 'target_porsi' => 100];

        $this->sebagai($this->ahliGiziA)
            ->patchJson("/api/v1/menu-gizi/{$menu->id}", ['nama_menu' => 'Nasi Telur Uji Ubah', 'kelompok_sasaran' => 'siswa'])->assertOk();
        $this->assertSame('draf', $this->menuSegar($menu)->status_persetujuan->value);

        $this->sebagai($this->kepalaA)->postJson('/api/v1/jadwal', $body)->assertUnprocessable()->assertJsonValidationErrors(['menu_id']);
        $this->assertSame(0, JadwalProduksi::query()->withoutGlobalScopes()->count());
    }

    // ------------------------------------------------------------------ R4 SPPG soft-deleted

    /** @return list<int> */
    private function penerimaInvestor(int $sppgId): array
    {
        $m = new ReflectionMethod(NotificationService::class, 'usersWithRoles');
        $m->setAccessible(true);

        return collect($m->invoke(app(NotificationService::class), [Role::Investor], $sppgId))->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    public function test_notifikasi_investor_mengecualikan_sppg_terhapus_lunak(): void
    {
        $this->assertContains($this->investorAB->id, $this->penerimaInvestor($this->sppgA));
        $this->assertContains($this->investorAB->id, $this->penerimaInvestor($this->sppgB));
        $this->assertContains($this->investorC->id, $this->penerimaInvestor($this->sppgC));

        DB::table('sppgs')->where('id', $this->sppgB)->update(['deleted_at' => now()]);

        $this->assertSame([], $this->penerimaInvestor($this->sppgB));
        $this->assertContains($this->investorAB->id, $this->penerimaInvestor($this->sppgA));
        $this->assertNotContains($this->investorC->id, $this->penerimaInvestor($this->sppgA));
        $this->assertContains($this->investorC->id, $this->penerimaInvestor($this->sppgC));
    }
}
