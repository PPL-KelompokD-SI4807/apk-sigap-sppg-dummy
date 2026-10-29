<?php

namespace Tests\Feature\Dashboard;

use App\Enums\Role;
use App\Models\User;
use App\Services\Auth\SesiService;
use Laravel\Sanctum\Sanctum;

class DashboardAksesTest extends DashboardTestCase
{
    public function test_tanpa_login_401(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
    }

    public function test_token_pra_login_ditolak_403_perlu_login_penuh(): void
    {
        Sanctum::actingAs($this->kepalaA, [SesiService::ABILITY_PRA_LOGIN]);

        $this->getJson(self::URL)->assertForbidden()->assertJsonPath('alasan', 'perlu_login_penuh');
    }

    public function test_akun_nonaktif_ditolak(): void
    {
        $user = User::factory()->role(Role::KepalaSppg)->inactive()->create(['sppg_id' => $this->sppgA]);
        $this->as($user);

        $this->getJson(self::URL)->assertStatus(403);
    }

    public function test_peran_non_admin_tanpa_sppg_ditolak(): void
    {
        $tanpa = User::factory()->role(Role::Akuntan)->create(['sppg_id' => null]);
        $this->as($tanpa);

        $this->getJson(self::URL)->assertForbidden();
    }

    public function test_investor_tanpa_sppg_ditolak(): void
    {
        $this->sebagai($this->investor([]));

        $this->getJson(self::URL)->assertForbidden()->assertJsonPath('alasan', 'tanpa_sppg');
    }

    public function test_rentang_dan_sppg_id_divalidasi(): void
    {
        $this->as($this->kepalaA);

        $this->getJson(self::URL.'?rentang=14')->assertStatus(422)->assertJsonValidationErrors('rentang');
        $this->getJson(self::URL.'?rentang=abc')->assertStatus(422)->assertJsonValidationErrors('rentang');
        $this->getJson(self::URL.'?sppg_id=abc')->assertStatus(422)->assertJsonValidationErrors('sppg_id');
        $this->getJson(self::URL.'?sppg_id=0')->assertStatus(422)->assertJsonValidationErrors('sppg_id');
        $this->getJson(self::URL.'?sppg_id[]=1')->assertStatus(422)->assertJsonValidationErrors('sppg_id');
    }

    public function test_bentuk_respons_dan_kunci_widget_stabil_per_peran(): void
    {
        $harapan = [
            'kepala_sppg' => ['jadwal_produksi_hari_ini', 'porsi_target_vs_realisasi', 'distribusi_per_titik', 'mutu', 'keluhan_terbuka', 'stok_kritis', 'anggaran', 'bukti_nota', 'pengajuan_menunggu_investor', 'rekap_harian'],
            'ahli_gizi' => ['menu_hari_ini', 'gizi_vs_standar', 'biaya_per_porsi', 'kebutuhan_khusus', 'hasil_mutu'],
            'akuntan' => ['pencairan_vs_pengeluaran', 'serapan_per_kategori', 'pengeluaran_menunggu', 'selisih_tren', 'bukti_nota'],
            'staf_gudang' => ['stok_per_bahan', 'stok_di_bawah_minimum', 'stok_mendekati_kedaluwarsa', 'mutasi_hari_ini', 'pengiriman_pemasok'],
            'relawan_dapur' => ['menu_target_hari_ini', 'status_log_produksi', 'checklist_mutu_belum_diisi'],
            'relawan_distribusi' => ['rute_hari_ini', 'status_kirim', 'bukti_belum_diunggah', 'armada_pengemudi'],
            'admin' => ['operasional_lintas_sppg', 'pengguna_aktif_per_peran', 'login_gagal', 'antrean_job_webhook', 'draf_ai_tertunda'],
            'investor' => ['porsi_hari_ini', 'anggaran', 'pengajuan_menunggu', 'stok_kritis', 'insiden_mutu', 'keluhan_kritis', 'bukti_nota', 'tren'],
        ];
        $pengguna = [
            'kepala_sppg' => $this->kepalaA, 'ahli_gizi' => $this->ahliGiziA, 'akuntan' => $this->akuntanA, 'staf_gudang' => $this->stafGudangA,
            'relawan_dapur' => $this->relawanA, 'relawan_distribusi' => $this->relawanDistribusiA, 'admin' => $this->admin, 'investor' => $this->investorAB,
        ];

        foreach ($pengguna as $profil => $user) {
            $res = $this->sebagai($user)->getJson(self::URL)->assertOk();
            $data = $res->json('data');

            $this->assertSame($profil, $data['profil'], $profil);
            $this->assertSame($harapan[$profil], array_keys($data['widgets']), $profil);
            $this->assertSame(7, $data['rentang_hari']);
            $this->assertSame(self::HARI, $data['periode']['hari_ini']);
            $this->assertArrayHasKey('sppg', $data);

            foreach ($data['widgets'] as $kunci => $widget) {
                $this->assertIsArray($widget, "{$profil}.{$kunci} tidak boleh null untuk peran ini");
                $this->assertArrayHasKey('periode', $widget, "{$profil}.{$kunci}");
                $this->assertArrayHasKey('jenis', $widget['periode'], "{$profil}.{$kunci}");
                $this->assertSame('periode', array_key_first($widget), "{$profil}.{$kunci}");
            }
        }
    }

    public function test_relawan_tanpa_unit_mendapat_dashboard_kosong(): void
    {
        $relawan = User::factory()->role(Role::Relawan)->create(['sppg_id' => $this->sppgA]);
        $relawan->forceFill(['unit' => null])->saveQuietly();
        $this->as($relawan->fresh());

        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.profil', 'relawan_tanpa_unit')->assertJsonPath('data.widgets', []);
    }

    public function test_rentang_30_mengubah_panjang_tren(): void
    {
        $res = $this->sebagai($this->investorAB, $this->sppgA)->getJson(self::URL.'?rentang=30')->assertOk();

        $this->assertSame(30, $res->json('data.rentang_hari'));
        $this->assertCount(30, $res->json('data.widgets.tren.labels'));

        foreach ($res->json('data.widgets.tren.series') as $deret) {
            $this->assertCount(30, $deret);
        }

        $res7 = $this->sebagai($this->investorAB, $this->sppgA)->getJson(self::URL)->assertOk();
        $this->assertCount(7, $res7->json('data.widgets.tren.labels'));
        $this->assertSame(self::HARI, $res7->json('data.widgets.tren.labels.6'));
    }
}
