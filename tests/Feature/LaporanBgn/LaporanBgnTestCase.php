<?php

namespace Tests\Feature\LaporanBgn;

use App\Enums\KategoriPengeluaran;
use App\Enums\StatusPembelianBahan;
use App\Enums\StatusPersetujuanPengeluaran;
use App\Enums\SubKategoriPengeluaran;
use App\Models\JadwalProduksi;
use App\Models\LogProduksi;
use App\Models\PembelianBahan;
use App\Models\PencairanDana;
use App\Models\PenerimaManfaat;
use App\Models\Pengeluaran;
use App\Models\TitikLayanan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Investor\InvestorTestCase;

/**
 * Dasar tes R07-2 (Lampiran 30a-30d). Fixture fiktif SPPG A, November 2026 (1 Nov 2026 = Minggu, jadi 2 Nov = Senin):
 *  - Saldo awal Rp10.000.000 per 2026-11-01; pencairan Rp50.000.000 (2 Nov) dan Rp20.000.000 (20 Okt, sebelum saldo awal).
 *  - 2 Nov: bahan baku dari pembelian terverifikasi Rp120.000 + bahan baku tanpa pembelian Rp50.000; operasional:
 *    insentif_relawan Rp300.000, energi(+sub listrik) Rp150.000, energi lama Rp200.000; insentif fasilitas Rp6.000.000;
 *    pengeluaran `diajukan` Rp999.999 (tidak dihitung).
 *  - 3 Nov: bahan baku Rp80.000. Total realisasi 2-3 Nov = Rp6.900.000.
 *  - Produksi: 2 Nov 43 porsi (+3 Food Sample, +2 Uji), 3 Nov 86 porsi (= 2 x jumlah penerima aktif, estimasi per kelompok pas).
 *  - Penerima aktif SPPG A (43): SD 1-3 = 10, SMP = 20, ibu hamil = 3, balita = 3, jenjang kosong = 2, pendidik = 5;
 *    4 penerima SD 4-6 nonaktif tidak dihitung.
 *  - SPPG B: data "pengganggu" yang tidak boleh bocor ke SPPG A.
 */
abstract class LaporanBgnTestCase extends InvestorTestCase
{
    protected TitikLayanan $sdA;

    protected TitikLayanan $posyanduA;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('sppgs')->where('id', $this->sppgA)->update([
            'id_sppg_bgn' => 'SPPG-FIKTIF-001', 'kecamatan' => 'Kec. Fiktif', 'kab_kota' => 'Kab. Fiktif', 'provinsi' => 'Prov. Fiktif',
            'no_va' => '8800000000001', 'mitra_yayasan' => 'Yayasan Fiktif Sejahtera', 'nama_ketua_yayasan' => 'Ketua Fiktif',
            'saldo_awal' => 10_000_000, 'saldo_awal_tanggal' => '2026-11-01',
        ]);

        $this->sdA = TitikLayanan::factory()->create(['sppg_id' => $this->sppgA, 'nama' => 'SDN Fiktif 1']);
        $this->posyanduA = TitikLayanan::factory()->create(['sppg_id' => $this->sppgA, 'nama' => 'Posyandu Fiktif Melati']);

        $this->penerima($this->sdA, ['kelompok_sasaran' => 'siswa', 'jenjang' => 'sd_1_3'], 6, 'laki_laki');
        $this->penerima($this->sdA, ['kelompok_sasaran' => 'siswa', 'jenjang' => 'sd_1_3'], 4, 'perempuan');
        $this->penerima($this->sdA, ['kelompok_sasaran' => 'siswa', 'jenjang' => 'smp'], 20, 'perempuan');
        $this->penerima($this->posyanduA, ['kelompok_sasaran' => 'ibu_hamil'], 3, 'perempuan');
        $this->penerima($this->posyanduA, ['kelompok_sasaran' => 'balita'], 3, null);
        $this->penerima($this->sdA, ['kelompok_sasaran' => 'siswa', 'jenjang' => null], 2, null);
        $this->penerima($this->sdA, ['kelompok_sasaran' => 'pendidik'], 5, 'laki_laki');
        // Nonaktif: tidak dihitung.
        $this->penerima($this->sdA, ['kelompok_sasaran' => 'siswa', 'jenjang' => 'sd_4_6', 'is_active' => false], 4, 'laki_laki');

        $this->produksi('2026-11-02', 43, 3, 2);
        $this->produksi('2026-11-03', 86);

        PencairanDana::factory()->create(['sppg_id' => $this->sppgA, 'nominal' => 50_000_000, 'tanggal_cair' => '2026-11-02', 'periode' => '2026-11', 'sumber_dana' => 'BGN Fiktif Tahap I']);
        PencairanDana::factory()->create(['sppg_id' => $this->sppgA, 'nominal' => 20_000_000, 'tanggal_cair' => '2026-10-20', 'periode' => '2026-10']);

        $pembelian = PembelianBahan::factory()->create([
            'sppg_id' => $this->sppgA, 'tanggal_beli' => '2026-11-02', 'jumlah' => 10, 'satuan' => 'kg', 'harga_satuan' => 12_000, 'total' => 120_000,
            'status' => StatusPembelianBahan::Terverifikasi,
        ]);
        $pembelian->bahanBaku()->withTrashed()->first()?->forceFill(['nama' => 'Beras Fiktif'])->save();

        $this->keluar('2026-11-02', KategoriPengeluaran::BahanBaku, 120_000, ['pembelian_bahan_id' => $pembelian->id]);
        $this->keluar('2026-11-02', KategoriPengeluaran::BahanBaku, 50_000);
        $this->keluar('2026-11-02', KategoriPengeluaran::InsentifRelawan, 300_000, ['uraian' => 'Insentif relawan Budi Fiktif']);
        $this->keluar('2026-11-02', KategoriPengeluaran::Energi, 150_000, ['sub_kategori' => SubKategoriPengeluaran::Listrik]);
        $this->keluar('2026-11-02', KategoriPengeluaran::Energi, 200_000);
        $this->keluar('2026-11-02', KategoriPengeluaran::InsentifFasilitas, 6_000_000);
        $this->keluar('2026-11-02', KategoriPengeluaran::Lainnya, 999_999, ['status_persetujuan' => StatusPersetujuanPengeluaran::Diajukan]);
        $this->keluar('2026-11-03', KategoriPengeluaran::BahanBaku, 80_000);
        $this->keluar('2026-10-25', KategoriPengeluaran::Lainnya, 500_000); // sebelum saldo awal

        // SPPG B (pengganggu).
        PencairanDana::factory()->create(['sppg_id' => $this->sppgB, 'nominal' => 1_000_000_000, 'tanggal_cair' => '2026-11-02', 'periode' => '2026-11']);
        $this->keluar('2026-11-02', KategoriPengeluaran::BahanBaku, 777_777, [], $this->sppgB);
        $this->forgetActor();
    }

    /** @param array<string, mixed> $atribut */
    protected function penerima(TitikLayanan $titik, array $atribut, int $jumlah, ?string $kelamin): void
    {
        PenerimaManfaat::factory()->count($jumlah)->create($atribut + [
            'sppg_id' => $titik->sppg_id, 'titik_layanan_id' => $titik->id, 'jenis_kelamin' => $kelamin, 'is_active' => true,
        ]);
    }

    protected function produksi(string $tanggal, int $porsi, ?int $foodSample = null, ?int $uji = null, ?int $sppgId = null): void
    {
        $sppgId ??= $this->sppgA;
        $jadwal = JadwalProduksi::factory()->create(['sppg_id' => $sppgId, 'tanggal_produksi' => $tanggal, 'target_porsi' => $porsi]);
        LogProduksi::factory()->create([
            'sppg_id' => $sppgId, 'jadwal_produksi_id' => $jadwal->id, 'realisasi_porsi' => $porsi,
            'porsi_food_sample' => $foodSample, 'porsi_uji_organoleptik' => $uji,
        ]);
    }

    /** @param array<string, mixed> $tambahan */
    protected function keluar(string $tanggal, KategoriPengeluaran $kategori, int $nominal, array $tambahan = [], ?int $sppgId = null): Pengeluaran
    {
        return Pengeluaran::factory()->create($tambahan + [
            'sppg_id' => $sppgId ?? $this->sppgA, 'tanggal' => $tanggal, 'kategori' => $kategori, 'nominal' => $nominal,
            'uraian' => 'Belanja fiktif '.$kategori->value,
        ]);
    }

    /** @param array<string, mixed> $query */
    protected function laporan(string $jalur, array $query = []): TestResponse
    {
        return $this->getJson('/api/v1/laporan-bgn/'.$jalur.'?'.http_build_query($query));
    }

    /** @param array<string, mixed> $body */
    protected function terbitkan(string $jalur, array $body = []): TestResponse
    {
        return $this->postJson('/api/v1/laporan-bgn/'.$jalur.'/nomor', $body);
    }
}
