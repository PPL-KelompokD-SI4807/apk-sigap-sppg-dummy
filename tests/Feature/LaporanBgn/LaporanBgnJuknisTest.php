<?php

namespace Tests\Feature\LaporanBgn;

use App\Enums\BarisSasaranBgn;
use App\Enums\JenisLaporanBgn;
use App\Enums\KelompokPorsiBgn;
use App\Enums\SubKategoriPengeluaran;
use App\Models\Sppg;
use App\Services\LaporanBgn\LaporanBgnPdfService;
use App\Services\LaporanBgn\LaporanBgnService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * R07-5: uji kesesuaian struktur dan label PDF Lampiran 30a-30d terhadap Kepka BGN 401.1/2025 (hlm cetak 260-267) dan
 * daftar periksa docs/plan/2026-11-11-r07-ekspor-pdf-bgn.md. Berkas juknis (pindaian) tidak ada di git sehingga daftar
 * label di bawah adalah SALINAN literal dari juknis (dibaca dari gambar, lihat docs/ops/2026-11-15-uji-berdampingan-juknis.md),
 * bukan diturunkan dari kode. Tes ini mengunci label, urutan baris/kolom, dan blok tanda tangan; bukan tampilan visual.
 */
class LaporanBgnJuknisTest extends LaporanBgnTestCase
{
    /** 30b-A dan 30d-C (kolom peserta didik), urutan baku juknis. */
    private const PESERTA_DIDIK = [
        'PAUD/TK/RA/TKLB',
        'SD/MI/SD SLB Kelas 1-3',
        'SD/MI/SD SLB Kelas 4-6',
        'SMP/MTs/SMP SLB/Pesantren Kelas 1-3',
        'SMA/MA/SMK/SMA SLB/Pesantren Kelas 4-6',
        'ATS Usia < 9 tahun',
        'ATS Usia 9-18 Tahun',
        'Pendidik',
        'Tenaga Kependidikan',
    ];

    /** 30b-B baris non-peserta didik; di 30d-C kolom Balita memakai keterangan usia. */
    private const NON_PESERTA_30B = ['Ibu Hamil', 'Ibu Menyusui', 'Balita'];

    private const NON_PESERTA_30DC = ['Ibu Hamil', 'Ibu Menyusui', 'Balita (Non PAUD / 6-59 bulan)'];

    /** 30b-B: 17 baris baku Biaya Operasional (hlm cetak 262). */
    private const OPERASIONAL = [
        'Insentif Relawan',
        'Insentif Harian Penanggung Jawab Satuan Pendidikan',
        'Insentif Kader',
        'BPJS Ketenagakerjaan',
        'Listrik',
        'Air Kemasan Galon',
        'Air PDAM',
        'Air Minum',
        'Gas',
        'Sewa Kendaraan',
        'BBM',
        'Pulsa',
        'Internet',
        'ATK',
        'Alat Kebersihan',
        'APD',
        'Lain-lain',
    ];

    /** 30a: 15 kelompok Bab 4.7 juknis (Keputusan Q-1), urutan baku; baris estimasi diberi " *" oleh templat. */
    private const KELOMPOK_30A = [
        'Anak Balita *',
        'PAUD/TK/RA *',
        'SD/MI kelas 1-3 *',
        'SD/MI kelas 4-6 *',
        'SMP/MTS *',
        'SMA/MA/SMK *',
        'SLB *',
        'Santri *',
        'Pendidikan khusus lainnya *',
        'Pendidikan informal *',
        'Ibu hamil *',
        'Ibu menyusui *',
        'Food Sample (porsi)',
        'Uji Organoleptik (porsi)',
        'Pendidik dan Tenaga Kependidikan *',
    ];

    /** Bagian identitas + rincian keuangan + keterangan yang identik pada 30c dan 30d-A (hlm cetak 263 dan 265). */
    private const URUTAN_30C_30D_A = [
        'Nomor', 'Periode',
        'Yang bertandatangan di bawah ini:',
        'Nama', 'Jabatan', 'Yayasan', 'SPPG', 'ID SPPG', 'Kecamatan', 'Kab/Kota', 'Provinsi',
        'Dengan ini menyampaikan laporan penggunaan dana sebagai berikut:',
        'I. Rincian Keuangan',
        'Dana Pemasukan - Total',
        'Realisasi Anggaran',
        'Bahan Baku', 'Operasional', 'Insentif Fasilitas SPPG', 'Total',
        'Sisa Anggaran - Total',
        'II. Keterangan',
        '~Dana yang telah digunakan sesuai dengan kebutuhan kegiatan yang telah direncanakan dengan rincian sebagai',
        'Bahan Baku', 'Operasional', 'Insentif Fasilitas SPPG', 'No. Rekening / Virtual Account',
        '~Sisa dana sebesar Rp',
        'Pihak Pertama', 'Pihak Kedua', 'Mengetahui',
    ];

    /** @return array<string, array{string, string, string}> */
    public static function semuaJenis(): array
    {
        return [
            '30a' => ['porsi', '2026-11-02', '2026-11-15'],
            '30b' => ['harian', '2026-11-02', '2026-11-02'],
            '30c' => ['dua_mingguan', '2026-11-02', '2026-11-15'],
            '30d-A' => ['bulanan_ringkasan', '2026-11-01', '2026-11-30'],
            '30d-B' => ['bulanan_anggaran', '2026-11-01', '2026-11-30'],
            '30d-C' => ['bulanan_penerima', '2026-11-01', '2026-11-30'],
        ];
    }

    // ------------------------------------------------------------------ pembantu

    private function pdf(string $jenis, string $awal, string $akhir): string
    {
        $this->forgetActor();
        $sppg = Sppg::query()->withoutGlobalScopes()->findOrFail($this->sppgA);
        $berkas = app(LaporanBgnPdfService::class)->buat(
            $this->akuntanA, JenisLaporanBgn::from($jenis), $sppg, CarbonImmutable::parse($awal), CarbonImmutable::parse($akhir),
        );
        $isi = (string) file_get_contents($berkas);
        unlink($berkas);

        return $isi;
    }

    /**
     * Teks tampil (operator Tj) per halaman, urut kemunculan; tiap halaman satu aliran konten. Halaman tanpa teks dilewati.
     *
     * @return list<list<string>> baris teks yang dipangkas per halaman
     */
    private function halamanTeks(string $pdf): array
    {
        $hasil = [];
        // Jangan pakai "\r?" di akhir: byte terakhir data zlib bisa kebetulan "\r" (lihat LaporanBgnPdfTest).
        preg_match_all('/stream\n(.*?)\nendstream/s', $pdf, $m);

        foreach ($m[1] as $aliran) {
            $buka = @gzuncompress($aliran);

            if ($buka === false) {
                continue;
            }

            preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)\s*Tj/s', $buka, $t);
            $baris = array_map(fn (string $s) => trim(str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $s)), $t[1]);

            if ($baris !== []) {
                $hasil[] = $baris;
            }
        }

        return $hasil;
    }

    /** @return list<string> */
    private function baris(string $pdf): array
    {
        return array_merge(...$this->halamanTeks($pdf));
    }

    /**
     * Setiap label harus muncul sebagai satu baris teks, berurutan (boleh disela baris lain). Label berawalan "~"
     * dicocokkan sebagai awalan baris (untuk kalimat yang dipatah otomatis).
     *
     * @param  list<string>  $baris
     * @param  list<string>  $label
     */
    private function assertUrut(array $baris, array $label, string $konteks = ''): void
    {
        $posisi = 0;

        foreach ($label as $l) {
            $awalan = str_starts_with($l, '~');
            $cari = $awalan ? substr($l, 1) : $l;
            $ketemu = null;

            for ($i = $posisi; $i < count($baris); $i++) {
                if ($awalan ? str_starts_with($baris[$i], $cari) : $baris[$i] === $cari) {
                    $ketemu = $i;
                    break;
                }
            }

            $this->assertNotNull($ketemu, "{$konteks}: label \"{$cari}\" tidak ditemukan setelah baris {$posisi} (urutan juknis).");
            $posisi = $ketemu + 1;
        }
    }

    // ------------------------------------------------------------------ 30a

    public function test_30a_judul_kolom_dan_15_kelompok_berurutan(): void
    {
        $baris = $this->baris($this->pdf('porsi', '2026-11-02', '2026-11-15'));

        $this->assertUrut($baris, ['REKAPITULASI PERHITUNGAN PORSI MBG di SPPG', 'Periode', 'Nama SPPG', 'No', 'KEBUTUHAN', 'ANGGARAN',
            '2 November 2026', '3 November 2026', '4 November 2026', '5 November 2026', '6 November 2026', 'TOTAL',
            'JUMLAH', 'PORSI', 'BIAYA PER HARI'], '30a kepala');

        // Dua tabel bertumpuk (dua pekan), tiap tabel memuat ke-15 kelompok berurutan lalu baris Total.
        $this->assertSame(2, count(array_filter($baris, fn (string $b) => str_starts_with($b, 'Pekan mulai'))));
        $this->assertUrut($baris, array_merge(self::KELOMPOK_30A, ['Total']), '30a tabel pekan 1');
        $this->assertUrut(array_slice($baris, (int) array_search('Pekan mulai 9 November 2026', $baris, true)), array_merge(self::KELOMPOK_30A, ['Total']), '30a tabel pekan 2');
    }

    public function test_30a_header_hari_tanpa_nama_hari_seperti_juknis(): void
    {
        $baris = $this->baris($this->pdf('porsi', '2026-11-02', '2026-11-15'));

        // Juknis: "17 Februari 2025" (tanggal panjang, tanpa nama hari).
        $this->assertContains('2 November 2026', $baris);
        $this->assertNotContains('Senin 02-11-2026', $baris);
    }

    public function test_30a_enum_kelompok_sama_dengan_15_baris_juknis(): void
    {
        $this->assertCount(15, KelompokPorsiBgn::cases());
        $label = array_map(fn (KelompokPorsiBgn $k) => $k->label(), KelompokPorsiBgn::cases());
        $diharapkan = array_map(fn (string $l) => preg_replace('/ \*$/', '', $l), self::KELOMPOK_30A);
        $this->assertSame($diharapkan, $label);
    }

    // ------------------------------------------------------------------ 30b

    public function test_30b_header_sasaran_belanja_dan_tanda_tangan_berurutan(): void
    {
        $baris = $this->baris($this->pdf('harian', '2026-11-02', '2026-11-02'));

        $this->assertUrut($baris, [
            'LAPORAN HARIAN PENGGUNAAN DANA PROGRAM MBG TA 2026',
            'Nomor', 'Nama SPPG', 'Kecamatan', 'Kab/Kota', 'Provinsi', 'Tanggal',
            'Sasaran Penerima Manfaat',
            'A. Peserta Didik', 'Kelompok Penerima Manfaat', 'Nama Sekolah', 'Jenis Kelamin', 'Jumlah', 'Jml Laki-laki', 'Jml', 'Perempuan',
            ...self::PESERTA_DIDIK, 'Total',
            'B. Non-Peserta Didik', 'Kelompok Penerima Manfaat', 'Nama Posyandu', 'Jenis Kelamin', 'Jumlah', 'Jml Laki-laki', 'Jml', 'Perempuan',
            ...self::NON_PESERTA_30B, 'Total',
            'Rincian Pembelanjaan',
            'A. Bahan Baku Pangan', 'Nama Bahan Baku', 'Jumlah', 'Unit Satuan', 'Harga Satuan', 'Total', 'Total Belanja Bahan Baku Pangan',
            'B. Biaya Operasional', 'Kebutuhan', 'Biaya per Hari (Rp)', 'Keterangan',
            ...self::OPERASIONAL, 'Total Biaya Operasional',
            'C. Biaya Insentif Fasilitas SPPG', 'Uraian', 'Jumlah', 'Keterangan', 'Biaya Insentif Fasilitas SPPG',
            'Pihak Pertama', 'Pihak Kedua', 'Ketua/Mewakili', 'Mengetahui',
        ], '30b');
    }

    public function test_30b_balita_tanpa_keterangan_usia_dan_17_baris_operasional(): void
    {
        $baris = $this->baris($this->pdf('harian', '2026-11-02', '2026-11-02'));

        // Juknis 30b-B menulis "Balita" saja (keterangan usia hanya pada kolom 30d-C).
        $this->assertContains('Balita', $baris);
        $this->assertNotContains('Balita (Non PAUD / 6-59 bulan)', $baris);

        $this->assertCount(17, self::OPERASIONAL);
        $this->assertSame(self::OPERASIONAL, array_map(fn (SubKategoriPengeluaran $s) => $s->label(), SubKategoriPengeluaran::cases()));
    }

    public function test_enum_baris_sasaran_sama_dengan_juknis(): void
    {
        $peserta = $non30b = $non30dc = [];

        foreach (BarisSasaranBgn::cases() as $b) {
            if ($b->pesertaDidik()) {
                $peserta[] = $b->label();
            } else {
                $non30b[] = $b->labelHarian();
                $non30dc[] = $b->label();
            }
        }

        $this->assertSame(self::PESERTA_DIDIK, $peserta);
        $this->assertSame(self::NON_PESERTA_30B, $non30b);
        $this->assertSame(self::NON_PESERTA_30DC, $non30dc);
    }

    // ------------------------------------------------------------------ 30c dan 30d

    public function test_30c_urutan_identitas_keuangan_keterangan_dan_tanda_tangan(): void
    {
        $baris = $this->baris($this->pdf('dua_mingguan', '2026-11-02', '2026-11-15'));

        $this->assertUrut($baris, ['LAPORAN 2 (DUA) MINGGUAN PENGGUNAAN DANA PROGRAM MBG TA 2026', ...self::URUTAN_30C_30D_A], '30c');
        $this->assertContains('Ketua/Mewakili', $baris);
        $this->assertContains('Periode', $baris);
        $this->assertContains('02-11-2026 s/d 15-11-2026', $baris);
    }

    public function test_30d_a_sama_dengan_30c_dengan_judul_dan_periode_bulan(): void
    {
        $baris = $this->baris($this->pdf('bulanan_ringkasan', '2026-11-01', '2026-11-30'));

        $this->assertUrut($baris, ['LAPORAN BULANAN PENGGUNAAN DANA PROGRAM MBG TA 2026', 'A. Laporan Bulanan (Summary)', ...self::URUTAN_30C_30D_A], '30d-A');
        $this->assertContains('Bulan November 2026', $baris);
    }

    public function test_30d_b_kolom_urutan_baris_dan_catatan_kaki(): void
    {
        $baris = $this->baris($this->pdf('bulanan_anggaran', '2026-11-01', '2026-11-30'));

        $this->assertUrut($baris, [
            'LAPORAN BULANAN PENGGUNAAN DANA PROGRAM MBG TA 2026', 'B. Laporan Bulanan (Anggaran)', 'Nomor', 'Periode', 'Bulan November 2026',
            'No', 'Tanggal', 'Perihal', 'Dana Masuk', 'Dana Keluar', 'Saldo', 'Keterangan',
            'Penerimaan Saldo Awal',
            'Pembelanjaan:',
            'Bahan Baku Pangan', 'Biaya Operasional', 'Insentif Fasilitas SPPG',
            'Total',
            '*dibuat dari tanggal awal sampai dengan akhir bulan',
        ], '30d-B');
    }

    public function test_salah_ketik_juknis_tidak_disalin_q9(): void
    {
        $semua = '';

        foreach (self::semuaJenis() as [$jenis, $awal, $akhir]) {
            $semua .= implode("\n", $this->baris($this->pdf($jenis, $awal, $akhir)))."\n";
        }

        // Juknis 30d-B menulis "Pembelajaan:" (salah ketik); Keputusan Q-9: ejaan benar "Pembelanjaan".
        $this->assertStringContainsString('Pembelanjaan:', $semua);
        $this->assertStringNotContainsString('Pembelajaan', $semua);
        $this->assertStringNotContainsString('Pembelaajan', $semua);
        // Tanggal contoh juknis 30a ("17 Februari 2025") bukan bagian templat.
        $this->assertStringNotContainsString('Februari 2025', $semua);
    }

    public function test_30d_c_17_kolom_urutan_baku_dan_baris_per_hari(): void
    {
        $this->forgetActor();
        $sppg = Sppg::query()->withoutGlobalScopes()->findOrFail($this->sppgA);
        $data = app(LaporanBgnService::class)->susun(JenisLaporanBgn::BulananPenerima, $sppg, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'), $this->akuntanA);

        $peserta = array_column($data['kolom']['peserta_didik'], 'label');
        $non = array_column($data['kolom']['non_peserta_didik'], 'label');
        $this->assertSame(self::PESERTA_DIDIK, $peserta);
        $this->assertSame(self::NON_PESERTA_30DC, $non);
        // No + Hari + Tanggal + Ket + 9 + 3 + Total = 17 kolom (kolom "Belum terklasifikasi" hanya bila ada data tak terklasifikasi).
        $this->assertSame(17, 4 + count($peserta) + count($non) + 1);
        $this->assertCount(30, $data['hari']);

        $baris = $this->baris($this->pdf('bulanan_penerima', '2026-11-01', '2026-11-30'));
        $this->assertUrut($baris, [
            'LAPORAN BULANAN PENGGUNAAN DANA PROGRAM MBG TA 2026', 'C. Laporan Bulanan (Penerima Manfaat)', 'Bulan', 'November 2026',
            'No', 'Hari', 'Tanggal', 'Ket', 'Peserta Didik', 'Non Peserta Didik', 'Total',
        ], '30d-C');
        $this->assertNotContains('Nomor', $baris, '30d-C tidak bernomor menurut juknis.');
    }

    public function test_kertas_folio_30a_lanskap_lainnya_potret_sesuai_juknis(): void
    {
        $potret = '/MediaBox [0 0 595.28 935.43]';
        $lanskap = '/MediaBox [0 0 935.43 595.28]';

        $this->assertStringContainsString($lanskap, $this->pdf('porsi', '2026-11-02', '2026-11-15'));

        foreach (['harian' => ['2026-11-02', '2026-11-02'], 'dua_mingguan' => ['2026-11-02', '2026-11-15'], 'bulanan_ringkasan' => ['2026-11-01', '2026-11-30'],
            'bulanan_anggaran' => ['2026-11-01', '2026-11-30'], 'bulanan_penerima' => ['2026-11-01', '2026-11-30']] as $jenis => [$awal, $akhir]) {
            $pdf = $this->pdf($jenis, $awal, $akhir);
            $this->assertStringContainsString($potret, $pdf, $jenis);
            $this->assertStringNotContainsString($lanskap, $pdf, $jenis);
        }
    }

    // ------------------------------------------------------------------ blok tanda tangan (semua jenis)

    #[DataProvider('semuaJenis')]
    public function test_blok_tanda_tangan_tiga_pihak_utuh_dalam_satu_halaman(string $jenis, string $awal, string $akhir): void
    {
        $halaman = $this->halamanTeks($this->pdf($jenis, $awal, $akhir));
        $kepala = $this->kepalaA->name;
        $akuntan = $this->akuntanA->name;

        $blok = array_values(array_filter($halaman, fn (array $h) => in_array('Pihak Pertama', $h, true)));
        $this->assertCount(1, $blok, "{$jenis}: blok tanda tangan harus tepat di satu halaman.");
        $h = $blok[0];

        // Juknis: Pihak Pertama (kiri), Pihak Kedua (kanan), lalu Mengetahui (tengah bawah); nama lengkap di bawah tiap jabatan.
        $this->assertUrut($h, ['Pihak Pertama', 'Yayasan Fiktif Sejahtera', 'Pihak Kedua', 'Staf Akuntansi SPPG A', 'Ketua Fiktif', 'Ketua/Mewakili', $akuntan, 'Mengetahui', 'Kepala SPPG A', $kepala], $jenis);
    }

    public function test_jabatan_tidak_menggandakan_kata_sppg_pada_nama_sppg(): void
    {
        $semua = implode("\n", $this->baris($this->pdf('harian', '2026-11-02', '2026-11-02')));

        $this->assertStringNotContainsString('SPPG SPPG', $semua);
        $this->assertStringContainsString("Staf Akuntansi SPPG A\n", $semua);
        $this->assertStringContainsString("Kepala SPPG A\n", $semua);
    }
}
