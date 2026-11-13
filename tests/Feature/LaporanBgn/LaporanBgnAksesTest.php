<?php

namespace Tests\Feature\LaporanBgn;

use App\Models\TitikLayanan;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * RBAC + scope SPPG laporan BGN: 403 tanpa hak, lintas SPPG, investor hanya agregat (Q-4) dengan ambang k=5.
 */
class LaporanBgnAksesTest extends LaporanBgnTestCase
{
    /** @return array<string, array{string, array<string, string>}> jalur => query minimal */
    public static function semuaJenis(): array
    {
        $dua = ['tanggal_awal' => '2026-11-01', 'tanggal_akhir' => '2026-11-15'];

        return [
            '30a' => ['porsi', $dua],
            '30b' => ['harian', ['tanggal' => '2026-11-02']],
            '30c' => ['dua-mingguan', $dua],
            '30d-A' => ['bulanan/ringkasan', ['bulan' => '2026-11']],
            '30d-B' => ['bulanan/anggaran', ['bulan' => '2026-11']],
            '30d-C' => ['bulanan/penerima-manfaat', ['bulan' => '2026-11']],
        ];
    }

    /** @return array<string, array{string, array<string, string>}> */
    public static function jenisAgregat(): array
    {
        return array_diff_key(self::semuaJenis(), ['30b' => 1, '30d-C' => 1]);
    }

    /** @return array<string, array{string, array<string, string>}> */
    public static function jenisPenuh(): array
    {
        return array_intersect_key(self::semuaJenis(), ['30b' => 1, '30d-C' => 1]);
    }

    #[DataProvider('semuaJenis')]
    public function test_tanpa_login_401(string $jalur, array $query): void
    {
        $this->forgetActor();
        $this->flushHeaders();
        $this->laporan($jalur, $query)->assertUnauthorized();
    }

    #[DataProvider('semuaJenis')]
    public function test_peran_tanpa_hak_403_bahkan_sebelum_validasi(string $jalur, array $query): void
    {
        foreach ([$this->ahliGiziA, $this->stafGudangA, $this->relawanA] as $user) {
            $this->as($user)->laporan($jalur, $query)->assertForbidden();
            // Tanpa parameter: tetap 403 (bukan 422 yang membocorkan bentuk endpoint).
            $this->as($user)->laporan($jalur)->assertForbidden();
        }
    }

    #[DataProvider('semuaJenis')]
    public function test_akuntan_kepala_dan_admin_boleh(string $jalur, array $query): void
    {
        $this->as($this->akuntanA)->laporan($jalur, $query)->assertOk();
        $this->as($this->kepalaA)->laporan($jalur, $query)->assertOk();
        $this->as($this->admin)->laporan($jalur, $query + ['sppg_id' => $this->sppgA])->assertOk()
            ->assertJsonPath('data.sppg.id', $this->sppgA);
    }

    #[DataProvider('semuaJenis')]
    public function test_lintas_sppg_403_dan_data_tidak_bocor(string $jalur, array $query): void
    {
        // Kepala B meminta data SPPG A secara eksplisit.
        $this->as($this->kepalaB)->laporan($jalur, $query + ['sppg_id' => $this->sppgA])->assertForbidden();
        // Tanpa sppg_id: hanya SPPG sendiri (B), tanpa angka SPPG A.
        $res = $this->as($this->kepalaB)->laporan($jalur, $query)->assertOk();
        $this->assertSame($this->sppgB, $res->json('data.sppg.id'));
        $this->assertStringNotContainsString('Beras Fiktif', $res->getContent());
        $this->assertStringNotContainsString('SDN Fiktif 1', $res->getContent());
    }

    public function test_data_sppg_b_tidak_masuk_laporan_sppg_a(): void
    {
        $res = $this->as($this->kepalaA)->laporan('dua-mingguan', ['tanggal_awal' => '2026-11-01', 'tanggal_akhir' => '2026-11-15'])->assertOk();
        $res->assertJsonPath('data.rincian_keuangan.dana_pemasukan_total', 50_000_000)
            ->assertJsonPath('data.rincian_keuangan.realisasi_anggaran.bahan_baku', 250_000);

        $b = $this->as($this->kepalaB)->laporan('dua-mingguan', ['tanggal_awal' => '2026-11-01', 'tanggal_akhir' => '2026-11-15'])->assertOk();
        $b->assertJsonPath('data.rincian_keuangan.dana_pemasukan_total', 1_000_000_000)
            ->assertJsonPath('data.rincian_keuangan.realisasi_anggaran.bahan_baku', 777_777)
            ->assertJsonPath('data.rincian_keuangan.saldo_awal', 0);
        $this->assertContains('saldo_awal_belum_diisi', array_column($b->json('data.peringatan'), 'kode'));
    }

    public function test_admin_wajib_memilih_sppg_yang_ada(): void
    {
        $this->as($this->admin)->laporan('harian', ['tanggal' => '2026-11-02'])->assertStatus(422)->assertJsonValidationErrors('sppg_id');
        $this->as($this->admin)->laporan('harian', ['tanggal' => '2026-11-02', 'sppg_id' => 999_999])->assertStatus(422)->assertJsonValidationErrors('sppg_id');
    }

    // ------------------------------------------------------------------ investor (Q-4)

    #[DataProvider('jenisAgregat')]
    public function test_investor_boleh_agregat_pada_sppg_miliknya(string $jalur, array $query): void
    {
        $this->sebagai($this->investorAB, $this->sppgA)->laporan($jalur, $query)->assertOk()
            ->assertJsonPath('data.sppg.id', $this->sppgA);
    }

    #[DataProvider('jenisPenuh')]
    public function test_investor_30b_dan_30d_c_403(string $jalur, array $query): void
    {
        $this->sebagai($this->investorAB, $this->sppgA)->laporan($jalur, $query)->assertForbidden();
        $this->sebagai($this->investorAB, $this->sppgA)->laporan($jalur)->assertForbidden();
    }

    #[DataProvider('semuaJenis')]
    public function test_investor_sppg_bukan_miliknya_403(string $jalur, array $query): void
    {
        // investor C hanya memiliki SPPG C: header SPPG A ditolak middleware; query sppg_id = A ditolak Policy.
        $this->sebagai($this->investorC, $this->sppgA)->laporan($jalur, $query)->assertForbidden();
        $this->sebagai($this->investorC, $this->sppgC)->laporan($jalur, $query + ['sppg_id' => $this->sppgA])->assertForbidden();
    }

    public function test_investor_tanpa_header_sppg_ditolak(): void
    {
        $this->sebagai($this->investorAB)->laporan('dua-mingguan', ['tanggal_awal' => '2026-11-01', 'tanggal_akhir' => '2026-11-15'])
            ->assertStatus(409);
    }

    public function test_investor_30a_kelompok_kecil_digabung_dan_tidak_membocorkan_penerima(): void
    {
        $res = $this->sebagai($this->investorAB, $this->sppgA)
            ->laporan('porsi', ['tanggal_awal' => '2026-11-02', 'tanggal_akhir' => '2026-11-06'])->assertOk();

        $res->assertJsonPath('data.penyamaran', ['diterapkan' => true, 'ambang_k' => 5, 'penanda' => '<5']);
        $baris = collect($res->json('data.tabel.0.baris'))->keyBy('kelompok');

        // Ibu hamil (3), anak balita (3), belum terklasifikasi (2) < 5 disembunyikan dan digabung.
        foreach (['ibu_hamil', 'anak_balita', 'belum_terklasifikasi'] as $kecil) {
            $this->assertArrayNotHasKey($kecil, $baris->all(), "{$kecil} harus digabung");
        }
        // Kelompok >= 5 tetap terlihat; Food Sample/Uji (porsi, bukan orang) tetap.
        $this->assertSame(10, $baris['sd_1_3']['per_hari'][0]['porsi']);
        $this->assertSame(20, $baris['smp']['per_hari'][0]['porsi']);
        $this->assertSame(5, $baris['pendidik_tenaga_kependidikan']['per_hari'][0]['porsi']);
        $this->assertSame(3, $baris['food_sample']['per_hari'][0]['porsi']);

        // Baris gabungan = 3 + 3 + 2 = 8 porsi pada 2 Nov; total tetap utuh.
        $this->assertSame('<5', $baris['digabung']['penanda']);
        $this->assertSame(8, $baris['digabung']['per_hari'][0]['porsi']);
        $this->assertSame(48, $res->json('data.tabel.0.total.per_hari.0.porsi'));
        $this->assertNull($res->json('data.tabel.0.total.penanda'));

        // Tanpa data pribadi: tidak ada nama titik layanan/sekolah, jenis kelamin, atau NIK di respons.
        $isi = $res->getContent();
        foreach (['SDN Fiktif', 'Posyandu', 'laki_laki', 'nik', 'catatan_alergi'] as $terlarang) {
            $this->assertStringNotContainsStringIgnoringCase($terlarang, $isi);
        }
    }

    public function test_investor_30a_satu_sel_kecil_menarik_sel_pelengkap_ke_gabungan(): void
    {
        // SPPG C: satu kelompok kecil (3 balita) dan satu besar (6 siswa SD 1-3). Sel pelengkap (kelompok besar) ikut
        // digabung agar balita tidak dapat dihitung dari total - sel terlihat; gabungan >= k sehingga total boleh tampil.
        $titik = TitikLayanan::factory()->create(['sppg_id' => $this->sppgC, 'nama' => 'SDN Fiktif C']);
        $this->penerima($titik, ['kelompok_sasaran' => 'balita'], 3, null);
        $this->penerima($titik, ['kelompok_sasaran' => 'siswa', 'jenjang' => 'sd_1_3'], 6, null);
        $this->produksi('2026-11-02', 18, null, null, $this->sppgC);
        $this->forgetActor();

        $res = $this->sebagai($this->investorC, $this->sppgC)
            ->laporan('porsi', ['tanggal_awal' => '2026-11-02', 'tanggal_akhir' => '2026-11-06'])->assertOk();
        $baris = collect($res->json('data.tabel.0.baris'))->keyBy('kelompok');

        $this->assertArrayNotHasKey('anak_balita', $baris->all());
        $this->assertArrayNotHasKey('sd_1_3', $baris->all(), 'sel pelengkap ikut digabung');
        $this->assertSame(18, $baris['digabung']['per_hari'][0]['porsi']);
        $this->assertSame(18, $res->json('data.tabel.0.total.per_hari.0.porsi'));
    }

    public function test_investor_30a_sppg_sangat_kecil_seluruh_rincian_digabung(): void
    {
        $titik = TitikLayanan::factory()->create(['sppg_id' => $this->sppgC, 'nama' => 'Posyandu Fiktif C']);
        $this->penerima($titik, ['kelompok_sasaran' => 'ibu_hamil'], 3, null);
        $this->produksi('2026-11-02', 3, null, null, $this->sppgC);
        $this->forgetActor();

        $res = $this->sebagai($this->investorC, $this->sppgC)
            ->laporan('porsi', ['tanggal_awal' => '2026-11-02', 'tanggal_akhir' => '2026-11-06'])->assertOk();
        $baris = collect($res->json('data.tabel.0.baris'))->pluck('kelompok')->all();

        $this->assertNotContains('ibu_hamil', $baris, 'kelompok tunggal kecil tidak boleh terlihat');
        $this->assertContains('digabung', $baris);
    }

    public function test_investor_30d_b_memakai_label_baku_bukan_teks_bebas(): void
    {
        $res = $this->sebagai($this->investorAB, $this->sppgA)->laporan('bulanan/anggaran', ['bulan' => '2026-11'])->assertOk();

        $this->assertStringNotContainsString('Budi Fiktif', $res->getContent());
        $this->assertStringNotContainsString('Belanja fiktif', $res->getContent());
        $perihal = array_column($res->json('data.baris'), 'perihal');
        $this->assertContains('Insentif Relawan', $perihal);
        $res->assertJsonPath('data.total.dana_keluar', 6_900_000);

        // Peran penuh melihat uraian aslinya.
        $kepala = $this->as($this->kepalaA)->laporan('bulanan/anggaran', ['bulan' => '2026-11'])->assertOk();
        $this->assertContains('Insentif relawan Budi Fiktif', array_column($kepala->json('data.baris'), 'perihal'));
    }

    public function test_pengguna_nonaktif_tidak_punya_hak(): void
    {
        $akuntan = $this->akuntanA;
        User::query()->whereKey($akuntan->id)->update(['is_active' => false]);

        $this->as($akuntan->refresh())->laporan('harian', ['tanggal' => '2026-11-02'])->assertForbidden();
    }
}
