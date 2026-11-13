<?php

namespace Tests\Feature\Bgn;

use App\Enums\KategoriPengeluaran;
use App\Enums\KelompokSasaran;
use App\Enums\Role;
use App\Enums\SubKategoriPengeluaran;
use App\Models\AuditLog;
use App\Models\JadwalProduksi;
use App\Models\PenerimaManfaat;
use App\Models\Pengeluaran;
use App\Models\TitikLayanan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Master\MasterApiTestCase;

/**
 * R07-1: kolom fondasi ekspor PDF format BGN (Lampiran 30). Migrasi hanya ADD COLUMN nullable (data lama tetap),
 * saldo awal hanya Akuntan/Admin + audit, klasifikasi penerima, sub-kategori pengeluaran, porsi sampel log produksi,
 * dan regresi dampak perluasan enum.
 */
class KolomBgnTest extends MasterApiTestCase
{
    private const MIGRASI = [
        '2026_11_15_000101_add_identitas_bgn_to_sppgs_table' => ['sppgs', ['id_sppg_bgn', 'kecamatan', 'kab_kota', 'provinsi', 'no_va', 'nama_ketua_yayasan']],
        '2026_11_15_000102_add_saldo_awal_dan_anggaran_to_sppgs_table' => ['sppgs', ['saldo_awal', 'saldo_awal_tanggal', 'acuan_bahan_baku_kecil', 'acuan_bahan_baku_lainnya', 'insentif_fasilitas_per_hari', 'insentif_fasilitas_maks_hari_minggu']],
        '2026_11_15_000103_add_klasifikasi_bgn_to_penerima_manfaats_table' => ['penerima_manfaats', ['jenis_kelamin', 'jenjang', 'jenis_satuan']],
        '2026_11_15_000104_add_sub_kategori_to_pengeluarans_table' => ['pengeluarans', ['sub_kategori']],
        '2026_11_15_000105_add_porsi_sampel_to_log_produksis_table' => ['log_produksis', ['porsi_food_sample', 'porsi_uji_organoleptik']],
    ];

    private TitikLayanan $titikA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->titikA = TitikLayanan::factory()->create(['sppg_id' => $this->sppgA, 'nama' => 'SDN A1']);
    }

    // ---------------------------------------------------------------- migrasi

    public function test_migrasi_naik_turun_tanpa_mengubah_data_lama(): void
    {
        $this->forgetActor();
        $penerima = PenerimaManfaat::factory()->create(['sppg_id' => $this->sppgA, 'titik_layanan_id' => $this->titikA->id, 'nama' => 'B.S.']);
        $pengeluaran = Pengeluaran::factory()->create(['sppg_id' => $this->sppgA, 'nominal' => 123_000]);

        foreach (self::MIGRASI as $berkas => [$tabel, $kolom]) {
            $migrasi = require database_path("migrations/{$berkas}.php");

            foreach ($kolom as $k) {
                $this->assertTrue(Schema::hasColumn($tabel, $k), "{$tabel}.{$k} ada");
            }

            $migrasi->down();

            foreach ($kolom as $k) {
                $this->assertFalse(Schema::hasColumn($tabel, $k), "{$tabel}.{$k} hilang setelah down");
            }

            $this->assertSame('B.S.', DB::table('penerima_manfaats')->where('id', $penerima->id)->value('nama'));
            $this->assertSame(1, DB::table('pengeluarans')->where('id', $pengeluaran->id)->count());

            $migrasi->up();

            // Tanpa backfill: seluruh kolom baru NULL pada baris lama.
            foreach ($kolom as $k) {
                $this->assertSame(0, DB::table($tabel)->whereNotNull($k)->count(), "{$tabel}.{$k} tetap NULL");
            }
        }

        $this->assertSame(123_000, (int) DB::table('pengeluarans')->where('id', $pengeluaran->id)->value('nominal'));
    }

    // ---------------------------------------------------------------- fitur 3: identitas + anggaran

    public function test_kepala_mengisi_identitas_bgn_dan_pengaturan_anggaran_sppg_sendiri(): void
    {
        $this->as($this->kepalaA)->putJson("/api/v1/sppg/{$this->sppgA}", [
            'id_sppg_bgn' => 'BGN-3201-0001',
            'kecamatan' => 'Kec. Fiktif',
            'kab_kota' => 'Kab. Contoh',
            'provinsi' => 'Provinsi Uji',
            'no_va' => '8800 1234-56',
            'nama_ketua_yayasan' => 'Ketua Fiktif',
            'acuan_bahan_baku_kecil' => 7500,
            'insentif_fasilitas_per_hari' => 5_000_000,
            'insentif_fasilitas_maks_hari_minggu' => 5,
        ])->assertOk()
            ->assertJsonPath('data.id_sppg_bgn', 'BGN-3201-0001')
            ->assertJsonPath('data.kab_kota', 'Kab. Contoh')
            ->assertJsonPath('data.no_va', '8800 1234-56')
            ->assertJsonPath('data.nama_ketua_yayasan', 'Ketua Fiktif')
            ->assertJsonPath('data.pengaturan_anggaran', [
                'acuan_bahan_baku_kecil' => 7500,
                'acuan_bahan_baku_lainnya' => 10000,
                'insentif_fasilitas_per_hari' => 5_000_000,
                'insentif_fasilitas_maks_hari_minggu' => 5,
            ])
            ->assertJsonPath('data.pengaturan_anggaran_kustom.acuan_bahan_baku_lainnya', null);
    }

    public function test_nilai_bawaan_pengaturan_anggaran_dari_kode_bukan_dari_db(): void
    {
        $res = $this->as($this->kepalaA)->getJson("/api/v1/sppg/{$this->sppgA}")->assertOk();

        $this->assertSame(['acuan_bahan_baku_kecil' => 8000, 'acuan_bahan_baku_lainnya' => 10000, 'insentif_fasilitas_per_hari' => 6_000_000, 'insentif_fasilitas_maks_hari_minggu' => 6], $res->json('data.pengaturan_anggaran'));
        $this->assertNull(DB::table('sppgs')->where('id', $this->sppgA)->value('acuan_bahan_baku_kecil'));
        $this->assertNull($res->json('data.saldo_awal'));
    }

    public function test_validasi_identitas_dan_anggaran(): void
    {
        $this->as($this->kepalaA);

        foreach ([
            ['id_sppg_bgn' => 'ID salah!'],
            ['no_va' => 'abc'],
            ['nama_ketua_yayasan' => 'Ketua 3201010101010001'],
            ['acuan_bahan_baku_kecil' => 0],
            ['acuan_bahan_baku_lainnya' => 'banyak'],
            ['insentif_fasilitas_per_hari' => -1],
            ['insentif_fasilitas_maks_hari_minggu' => 8],
            ['insentif_fasilitas_maks_hari_minggu' => 0],
        ] as $salah) {
            $this->patchJson("/api/v1/sppg/{$this->sppgA}", $salah)->assertUnprocessable()->assertJsonValidationErrors(array_keys($salah));
        }
    }

    public function test_peran_tanpa_hak_tidak_bisa_ubah_dan_lintas_sppg_ditolak(): void
    {
        foreach ([$this->ahliGiziA, $this->akuntanA, $this->stafGudangA, $this->relawanA] as $pengguna) {
            $this->as($pengguna)->patchJson("/api/v1/sppg/{$this->sppgA}", ['kecamatan' => 'X'])->assertForbidden();
        }

        $this->as($this->kepalaB)->patchJson("/api/v1/sppg/{$this->sppgA}", ['kecamatan' => 'X'])->assertForbidden();
        $this->assertNull(DB::table('sppgs')->where('id', $this->sppgA)->value('kecamatan'));
    }

    public function test_data_keuangan_sppg_tidak_terlihat_oleh_peran_tanpa_baca_keuangan(): void
    {
        DB::table('sppgs')->where('id', $this->sppgA)->update(['no_va' => '8800123', 'saldo_awal' => 5_000_000, 'nama_ketua_yayasan' => 'Ketua Fiktif']);

        foreach ([$this->relawanA, $this->stafGudangA, $this->ahliGiziA] as $pengguna) {
            $data = $this->as($pengguna)->getJson("/api/v1/sppg/{$this->sppgA}")->assertOk()->json('data');
            foreach (['no_va', 'saldo_awal', 'nama_ketua_yayasan', 'pengaturan_anggaran', 'izin'] as $kunci) {
                $this->assertArrayNotHasKey($kunci, $data, $kunci);
            }
        }

        $this->as($this->akuntanA)->getJson("/api/v1/sppg/{$this->sppgA}")->assertOk()
            ->assertJsonPath('data.saldo_awal', 5_000_000)->assertJsonPath('data.no_va', '8800123')
            ->assertJsonPath('data.izin.ubah_saldo_awal', true);
        $this->as($this->kepalaA)->getJson("/api/v1/sppg/{$this->sppgA}")->assertOk()
            ->assertJsonPath('data.saldo_awal', 5_000_000)->assertJsonPath('data.izin.ubah_saldo_awal', false);
    }

    // ---------------------------------------------------------------- saldo awal

    public function test_akuntan_mengisi_saldo_awal_dan_tercatat_di_audit(): void
    {
        $this->as($this->akuntanA)->putJson("/api/v1/sppg/{$this->sppgA}/saldo-awal", ['saldo_awal' => 25_000_000, 'saldo_awal_tanggal' => '2026-11-01'])
            ->assertOk()
            ->assertJsonPath('data.saldo_awal', 25_000_000)
            ->assertJsonPath('data.saldo_awal_tanggal', '2026-11-01');

        $log = AuditLog::query()->withoutGlobalScopes()->where('event', 'saldo_awal_diubah')->where('auditable_id', $this->sppgA)->sole();
        $this->assertSame($this->akuntanA->id, $log->user_id);
        $this->assertSame($this->sppgA, $log->sppg_id);
        $this->assertSame(['saldo_awal' => null, 'saldo_awal_tanggal' => null], $log->old_values);
        $this->assertSame(['saldo_awal' => 25_000_000, 'saldo_awal_tanggal' => '2026-11-01'], $log->new_values);

        // Perubahan berikutnya mencatat nilai lama; permintaan identik tidak menambah log.
        $this->patchJson("/api/v1/sppg/{$this->sppgA}/saldo-awal", ['saldo_awal' => 0, 'saldo_awal_tanggal' => '2026-11-01'])->assertOk()->assertJsonPath('data.saldo_awal', 0);
        $this->patchJson("/api/v1/sppg/{$this->sppgA}/saldo-awal", ['saldo_awal' => 0, 'saldo_awal_tanggal' => '2026-11-01'])->assertOk();
        $logs = AuditLog::query()->withoutGlobalScopes()->where('event', 'saldo_awal_diubah')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame(25_000_000, $logs[1]->old_values['saldo_awal']);
    }

    public function test_admin_boleh_mengisi_saldo_awal_sppg_mana_pun(): void
    {
        $this->as($this->admin)->putJson("/api/v1/sppg/{$this->sppgB}/saldo-awal", ['saldo_awal' => 1_000, 'saldo_awal_tanggal' => '2026-11-01'])
            ->assertOk()->assertJsonPath('data.saldo_awal', 1000);
    }

    public function test_saldo_awal_ditolak_403_untuk_peran_lain_dan_lintas_sppg(): void
    {
        $body = ['saldo_awal' => 1000, 'saldo_awal_tanggal' => '2026-11-01'];

        foreach ([$this->kepalaA, $this->ahliGiziA, $this->stafGudangA, $this->relawanA, $this->kepalaB] as $pengguna) {
            $this->as($pengguna)->putJson("/api/v1/sppg/{$this->sppgA}/saldo-awal", $body)->assertForbidden();
        }

        $investor = User::factory()->role(Role::Investor)->create(['sppg_id' => $this->sppgA]);
        $this->as($investor)->putJson("/api/v1/sppg/{$this->sppgA}/saldo-awal", $body)->assertForbidden();

        // Akuntan SPPG B tidak boleh mengubah SPPG A.
        $akuntanB = $this->userOf(Role::Akuntan, $this->sppgB);
        $this->as($akuntanB)->putJson("/api/v1/sppg/{$this->sppgA}/saldo-awal", $body)->assertForbidden();

        // 403 sebelum validasi (tanpa kebocoran aturan) dan tanpa jejak.
        $this->as($this->kepalaA)->putJson("/api/v1/sppg/{$this->sppgA}/saldo-awal", [])->assertForbidden();
        $this->assertNull(DB::table('sppgs')->where('id', $this->sppgA)->value('saldo_awal'));
        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()->where('event', 'saldo_awal_diubah')->count());
    }

    #[DataProvider('saldoSalah')]
    public function test_validasi_saldo_awal(array $body, string $kolom): void
    {
        $this->as($this->akuntanA)->putJson("/api/v1/sppg/{$this->sppgA}/saldo-awal", $body)->assertUnprocessable()->assertJsonValidationErrors([$kolom]);
        $this->assertNull(DB::table('sppgs')->where('id', $this->sppgA)->value('saldo_awal'));
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function saldoSalah(): array
    {
        return [
            'negatif' => [['saldo_awal' => -1, 'saldo_awal_tanggal' => '2026-11-01'], 'saldo_awal'],
            'pecahan' => [['saldo_awal' => 10.5, 'saldo_awal_tanggal' => '2026-11-01'], 'saldo_awal'],
            'bukan angka' => [['saldo_awal' => 'banyak', 'saldo_awal_tanggal' => '2026-11-01'], 'saldo_awal'],
            'kosong' => [['saldo_awal_tanggal' => '2026-11-01'], 'saldo_awal'],
            'tanpa tanggal' => [['saldo_awal' => 1000], 'saldo_awal_tanggal'],
            'tanggal format salah' => [['saldo_awal' => 1000, 'saldo_awal_tanggal' => '01-11-2026'], 'saldo_awal_tanggal'],
            'tanggal masa depan' => [['saldo_awal' => 1000, 'saldo_awal_tanggal' => '2999-01-01'], 'saldo_awal_tanggal'],
        ];
    }

    public function test_saldo_awal_tidak_bisa_diisi_lewat_endpoint_sppg_biasa(): void
    {
        $this->as($this->kepalaA)->patchJson("/api/v1/sppg/{$this->sppgA}", ['saldo_awal' => 999_999, 'saldo_awal_tanggal' => '2026-11-01'])->assertOk();
        $this->assertNull(DB::table('sppgs')->where('id', $this->sppgA)->value('saldo_awal'));
        $this->assertNull(DB::table('sppgs')->where('id', $this->sppgA)->value('saldo_awal_tanggal'));
    }

    // ---------------------------------------------------------------- fitur 5: penerima manfaat

    /** @param array<string, mixed> $o */
    private function payloadPenerima(array $o = []): array
    {
        return array_merge(['titik_layanan_id' => $this->titikA->id, 'nama' => 'A.R.', 'kelompok_sasaran' => 'siswa'], $o);
    }

    public function test_penerima_dengan_klasifikasi_bgn(): void
    {
        $this->as($this->kepalaA)->postJson('/api/v1/penerima-manfaat', $this->payloadPenerima(['jenis_kelamin' => 'perempuan', 'jenjang' => 'sd_4_6', 'jenis_satuan' => 'pesantren']))
            ->assertCreated()
            ->assertJsonPath('data.jenis_kelamin', 'perempuan')
            ->assertJsonPath('data.jenjang', 'sd_4_6')
            ->assertJsonPath('data.jenis_satuan', 'pesantren');

        $this->postJson('/api/v1/penerima-manfaat', $this->payloadPenerima(['kelompok_sasaran' => 'ats', 'jenjang' => 'ats_9_18', 'jenis_kelamin' => 'laki_laki']))
            ->assertCreated()->assertJsonPath('data.kelompok_sasaran', 'ats')->assertJsonPath('data.jenis_satuan', null);

        foreach (['pendidik', 'tenaga_kependidikan'] as $kelompok) {
            $this->postJson('/api/v1/penerima-manfaat', $this->payloadPenerima(['kelompok_sasaran' => $kelompok, 'jenis_kelamin' => 'laki_laki']))
                ->assertCreated()->assertJsonPath('data.kelompok_sasaran', $kelompok)->assertJsonPath('data.jenjang', null);
        }
    }

    public function test_data_penerima_lama_tidak_diisi_diam_diam(): void
    {
        $this->forgetActor();
        $lama = PenerimaManfaat::factory()->create(['sppg_id' => $this->sppgA, 'titik_layanan_id' => $this->titikA->id]);

        $this->as($this->kepalaA)->getJson("/api/v1/penerima-manfaat/{$lama->id}")->assertOk()
            ->assertJsonPath('data.jenis_kelamin', null)->assertJsonPath('data.jenjang', null)->assertJsonPath('data.jenis_satuan', null);

        // Mengubah field lain tidak menyentuh klasifikasi.
        $this->patchJson("/api/v1/penerima-manfaat/{$lama->id}", ['kelas_keterangan' => 'Kelas 2'])->assertOk()->assertJsonPath('data.jenjang', null);
    }

    #[DataProvider('klasifikasiSalah')]
    public function test_validasi_klasifikasi_penerima(array $override, string $kolom): void
    {
        $this->as($this->kepalaA)->postJson('/api/v1/penerima-manfaat', $this->payloadPenerima($override))
            ->assertUnprocessable()->assertJsonValidationErrors([$kolom]);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function klasifikasiSalah(): array
    {
        return [
            'jenis kelamin tak dikenal' => [['jenis_kelamin' => 'lainnya'], 'jenis_kelamin'],
            'jenjang tak dikenal' => [['jenjang' => 'kuliah'], 'jenjang'],
            'jenis satuan tak dikenal' => [['jenis_satuan' => 'swasta'], 'jenis_satuan'],
            'siswa dengan jenjang ATS' => [['jenjang' => 'ats_9_18'], 'jenjang'],
            'ats dengan jenjang sekolah' => [['kelompok_sasaran' => 'ats', 'jenjang' => 'smp'], 'jenjang'],
            'balita dengan jenjang' => [['kelompok_sasaran' => 'balita', 'jenjang' => 'paud_tk'], 'jenjang'],
            'ibu hamil dengan jenis satuan' => [['kelompok_sasaran' => 'ibu_hamil', 'jenis_satuan' => 'slb'], 'jenis_satuan'],
        ];
    }

    public function test_peran_tanpa_hak_ubah_klasifikasi_penerima_403(): void
    {
        foreach ([$this->ahliGiziA, $this->akuntanA, $this->stafGudangA, $this->relawanA] as $pengguna) {
            $this->as($pengguna)->postJson('/api/v1/penerima-manfaat', $this->payloadPenerima(['jenjang' => 'smp']))->assertForbidden();
        }

        // Lintas SPPG: titik layanan SPPG A tidak valid bagi kepala B (422 relasi tidak ditemukan; tidak ada data bocor).
        $this->as($this->kepalaB)->postJson('/api/v1/penerima-manfaat', $this->payloadPenerima())->assertUnprocessable();
    }

    // ---------------------------------------------------------------- fitur 14: sub_kategori

    public function test_tujuh_belas_sub_kategori_baku(): void
    {
        $this->assertCount(17, SubKategoriPengeluaran::cases());
        $this->assertSame(17, count(array_unique(array_map(fn ($c) => $c->label(), SubKategoriPengeluaran::cases()))));
        $this->assertSame('Insentif Harian Penanggung Jawab Satuan Pendidikan', SubKategoriPengeluaran::InsentifHarianPjSatuan->label());
        $this->assertSame('Lain-lain', SubKategoriPengeluaran::LainLain->label());
    }

    /** @param array<string, mixed> $o */
    private function payloadPengeluaran(array $o = []): array
    {
        return array_merge(['tanggal' => now()->toDateString(), 'kategori' => 'energi', 'uraian' => 'Isi ulang gas (uji)', 'nominal' => 150_000], $o);
    }

    public function test_pengeluaran_dengan_sub_kategori_dan_insentif_fasilitas(): void
    {
        $this->as($this->akuntanA);

        $this->postJson('/api/v1/keuangan/pengeluaran', $this->payloadPengeluaran(['sub_kategori' => 'gas']))
            ->assertCreated()->assertJsonPath('data.sub_kategori', 'gas')->assertJsonPath('data.sub_kategori_label', 'Gas');

        $this->postJson('/api/v1/keuangan/pengeluaran', $this->payloadPengeluaran(['kategori' => 'insentif_fasilitas', 'uraian' => 'Insentif fasilitas (uji)', 'nominal' => 6_000_000]))
            ->assertCreated()->assertJsonPath('data.kategori', 'insentif_fasilitas')->assertJsonPath('data.kategori_label', 'Insentif Fasilitas SPPG')->assertJsonPath('data.sub_kategori', null);

        $id = $this->postJson('/api/v1/keuangan/pengeluaran', $this->payloadPengeluaran())->assertCreated()->assertJsonPath('data.sub_kategori', null)->json('data.id');
        $versi = (int) $this->getJson("/api/v1/keuangan/pengeluaran/{$id}")->json('data.versi');
        $this->putJson("/api/v1/keuangan/pengeluaran/{$id}", ['versi' => $versi, 'sub_kategori' => 'listrik'])->assertOk()->assertJsonPath('data.sub_kategori', 'listrik');
        $this->getJson('/api/v1/keuangan/pengeluaran?kategori=insentif_fasilitas')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_validasi_sub_kategori(): void
    {
        $this->as($this->akuntanA);

        $this->postJson('/api/v1/keuangan/pengeluaran', $this->payloadPengeluaran(['sub_kategori' => 'tidak_ada']))->assertUnprocessable()->assertJsonValidationErrors(['sub_kategori']);
        $this->postJson('/api/v1/keuangan/pengeluaran', $this->payloadPengeluaran(['kategori' => 'bahan_baku', 'sub_kategori' => 'gas']))->assertUnprocessable()->assertJsonValidationErrors(['sub_kategori']);
        $this->postJson('/api/v1/keuangan/pengeluaran', $this->payloadPengeluaran(['kategori' => 'insentif_fasilitas', 'sub_kategori' => 'gas']))->assertUnprocessable()->assertJsonValidationErrors(['sub_kategori']);
        $this->postJson('/api/v1/keuangan/pengeluaran', $this->payloadPengeluaran(['kategori' => 'lain_lain']))->assertUnprocessable()->assertJsonValidationErrors(['kategori']);

        // Ubah ke bahan_baku sambil menyimpan sub-kategori lama ditolak lewat kategori efektif.
        $id = $this->postJson('/api/v1/keuangan/pengeluaran', $this->payloadPengeluaran())->assertCreated()->json('data.id');
        $this->putJson("/api/v1/keuangan/pengeluaran/{$id}", ['versi' => 1, 'kategori' => 'bahan_baku', 'sub_kategori' => 'gas'])->assertUnprocessable()->assertJsonValidationErrors(['sub_kategori']);
    }

    public function test_peran_tanpa_hak_tidak_bisa_mencatat_pengeluaran_dengan_sub_kategori(): void
    {
        foreach ([$this->kepalaA, $this->ahliGiziA, $this->stafGudangA, $this->relawanA] as $pengguna) {
            $this->as($pengguna)->postJson('/api/v1/keuangan/pengeluaran', $this->payloadPengeluaran(['sub_kategori' => 'gas']))->assertForbidden();
        }
    }

    // ---------------------------------------------------------------- fitur 10: porsi sampel

    public function test_log_produksi_porsi_food_sample_dan_uji_organoleptik_nullable(): void
    {
        $this->forgetActor();
        $jadwal = JadwalProduksi::factory()->create(['sppg_id' => $this->sppgA, 'target_porsi' => 1000, 'tanggal_produksi' => now()->toDateString()]);
        $jadwal2 = JadwalProduksi::factory()->create(['sppg_id' => $this->sppgA, 'target_porsi' => 1000, 'tanggal_produksi' => now()->toDateString()]);
        $this->as($this->relawanA);

        $this->postJson('/api/v1/log-produksi', ['jadwal_produksi_id' => $jadwal->id, 'realisasi_porsi' => 990, 'porsi_food_sample' => 5, 'porsi_uji_organoleptik' => 3])
            ->assertCreated()->assertJsonPath('data.porsi_food_sample', 5)->assertJsonPath('data.porsi_uji_organoleptik', 3)->assertJsonPath('data.realisasi_porsi', 990);

        // Tanpa isian: tetap valid, kolom NULL (kosong = 0 ditafsirkan pembuat laporan).
        $this->postJson('/api/v1/log-produksi', ['jadwal_produksi_id' => $jadwal2->id, 'realisasi_porsi' => 100])
            ->assertCreated()->assertJsonPath('data.porsi_food_sample', null)->assertJsonPath('data.porsi_uji_organoleptik', null);

        $this->postJson('/api/v1/log-produksi', ['jadwal_produksi_id' => $jadwal->id, 'realisasi_porsi' => 1, 'porsi_food_sample' => -1])->assertUnprocessable()->assertJsonValidationErrors(['porsi_food_sample']);
        $this->postJson('/api/v1/log-produksi', ['jadwal_produksi_id' => $jadwal->id, 'realisasi_porsi' => 1, 'porsi_uji_organoleptik' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['porsi_uji_organoleptik']);
    }

    public function test_log_produksi_porsi_sampel_ditolak_403_untuk_peran_tanpa_hak(): void
    {
        $this->forgetActor();
        $jadwal = JadwalProduksi::factory()->create(['sppg_id' => $this->sppgA, 'tanggal_produksi' => now()->toDateString()]);

        foreach ([$this->ahliGiziA, $this->akuntanA, $this->stafGudangA] as $pengguna) {
            $this->as($pengguna)->postJson('/api/v1/log-produksi', ['jadwal_produksi_id' => $jadwal->id, 'realisasi_porsi' => 10, 'porsi_food_sample' => 1])->assertForbidden();
        }
    }

    // ---------------------------------------------------------------- regresi dampak ::cases()

    public function test_nilai_enum_lama_tidak_berubah_urutan_dan_nilainya(): void
    {
        $this->assertSame(['siswa', 'balita', 'ibu_hamil', 'ibu_menyusui', 'pendidik', 'tenaga_kependidikan', 'ats'], KelompokSasaran::values());
        $this->assertSame(['bahan_baku', 'insentif_relawan', 'energi', 'transportasi', 'peralatan', 'lainnya', 'insentif_fasilitas'], KategoriPengeluaran::values());

        foreach (KelompokSasaran::cases() as $k) {
            $this->assertLessThanOrEqual(20, strlen($k->value), 'muat kolom string(20)');
        }
    }

    public function test_ringkasan_anggaran_kategori_lama_tidak_berubah_dan_insentif_fasilitas_ikut_terpakai(): void
    {
        $this->forgetActor();
        Pengeluaran::factory()->create(['sppg_id' => $this->sppgA, 'kategori' => KategoriPengeluaran::BahanBaku, 'nominal' => 4_000_000, 'tanggal' => now()->toDateString()]);
        Pengeluaran::factory()->create(['sppg_id' => $this->sppgA, 'kategori' => KategoriPengeluaran::InsentifFasilitas, 'nominal' => 6_000_000, 'tanggal' => now()->toDateString()]);

        $res = $this->as($this->akuntanA)->getJson('/api/v1/keuangan/ringkasan?periode='.now()->format('Y-m'))->assertOk();
        $kategori = collect($res->json('data.per_kategori'))->keyBy('kategori');

        $this->assertSame(10_000_000, $res->json('data.terpakai'));
        $this->assertSame(4_000_000, $kategori['bahan_baku']['nominal']);
        $this->assertSame(6_000_000, $kategori['insentif_fasilitas']['nominal']);
        $this->assertSame(0, $kategori['energi']['nominal']);
        $this->assertSame(['bahan_baku', 'insentif_relawan', 'energi', 'transportasi', 'peralatan', 'lainnya', 'insentif_fasilitas'], array_column($res->json('data.per_kategori'), 'kategori'));
    }

    public function test_agregat_penerima_kelompok_baru_dihitung_tanpa_mengubah_kelompok_lama(): void
    {
        $this->forgetActor();
        PenerimaManfaat::factory()->count(6)->create(['sppg_id' => $this->sppgA, 'titik_layanan_id' => $this->titikA->id, 'kelompok_sasaran' => KelompokSasaran::Siswa]);
        PenerimaManfaat::factory()->count(7)->create(['sppg_id' => $this->sppgA, 'titik_layanan_id' => $this->titikA->id, 'kelompok_sasaran' => KelompokSasaran::Pendidik]);

        $res = $this->as($this->kepalaA)->getJson('/api/v1/penerima-manfaat/agregat')->assertOk();
        $sel = collect($res->json('data.per_kelompok_sasaran'))->keyBy('kelompok_sasaran');

        $this->assertSame(6, $sel['siswa']['jumlah']);
        $this->assertSame(0, $sel['balita']['jumlah']);
        $this->assertSame(7, $sel['pendidik']['jumlah']);
        $this->assertSame(0, $sel['ats']['jumlah']);
        $this->assertSame(13, $res->json('data.aktif'));
    }

    public function test_menu_dan_standar_gizi_tetap_hanya_empat_kelompok_asli(): void
    {
        $this->assertSame(['siswa', 'balita', 'ibu_hamil', 'ibu_menyusui'], KelompokSasaran::valuesGizi());

        $this->as($this->ahliGiziA);
        $zat = [];
        foreach (['energi', 'protein', 'lemak', 'karbohidrat', 'serat'] as $z) {
            $zat["{$z}_min"] = 1;
            $zat["{$z}_max"] = 100;
        }

        foreach (['pendidik', 'tenaga_kependidikan', 'ats'] as $baru) {
            $this->postJson('/api/v1/menu-gizi/standar-gizi', ['kelompok_sasaran' => $baru] + $zat)->assertUnprocessable()->assertJsonValidationErrors(['kelompok_sasaran']);
        }

        $this->postJson('/api/v1/menu-gizi/standar-gizi', ['kelompok_sasaran' => 'siswa'] + $zat)->assertCreated();
    }
}
