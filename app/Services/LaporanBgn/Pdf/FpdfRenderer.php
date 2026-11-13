<?php

namespace App\Services\LaporanBgn\Pdf;

use RuntimeException;

/**
 * Render PDF Lampiran 30a-30d dengan setasign/fpdf (R07-3). Folio (config laporan-bgn.kertas_mm), font inti.
 * Halaman format BGN dan halaman lampiran bukti (foto nota) terpisah. Hanya angka agregat dan teks yang sudah
 * ada di keluaran LaporanBgnService::susun(); tidak ada kueri basis data di sini.
 */
final class FpdfRenderer implements PdfRenderer
{
    private const KET = ['produksi' => 'Produksi', 'rencana_distribusi' => 'Rencana', 'tidak_ada_produksi' => '-'];

    /** @var array<string, mixed> */
    private array $d = [];

    private LaporanPdf $pdf;

    public function render(array $laporan, iterable $bukti, string $tujuan, array $opsi = []): void
    {
        $this->d = $laporan;
        $jenis = (string) ($laporan['jenis'] ?? '');
        $kertas = $this->kertas();
        $lanskap = $jenis === 'porsi' || ($jenis === 'bulanan_penerima' && (bool) config('laporan-bgn.penerima_lanskap'));
        $this->pdf = new LaporanPdf($lanskap ? 'L' : 'P', $kertas, $lanskap ? 10.0 : 12.0);
        $this->pdf->SetTitle(Teks::tetap('Laporan BGN Lampiran '.($laporan['lampiran'] ?? '')), false);
        $this->pdf->halaman();

        match ($jenis) {
            'porsi' => $this->porsi(),
            'harian' => $this->harian(),
            'dua_mingguan' => $this->ringkasan(false),
            'bulanan_ringkasan' => $this->ringkasan(true),
            'bulanan_anggaran' => $this->anggaran(),
            'bulanan_penerima' => $this->penerima($lanskap),
            default => throw new RuntimeException('Jenis laporan tidak dikenal.'),
        };

        $this->lampiran($bukti, (int) ($opsi['bukti_dilewati'] ?? 0));
        $this->pdf->Output('F', $tujuan);
    }

    /** @return array{0: float, 1: float} */
    private function kertas(): array
    {
        $k = (array) config('laporan-bgn.kertas_mm', [210.0, 330.0]);

        return [(float) ($k[0] ?? 210.0), (float) ($k[1] ?? 330.0)];
    }

    // ------------------------------------------------------------------ 30a

    private function porsi(): void
    {
        $sppg = (array) $this->d['sppg'];
        $this->kop('REKAPITULASI PERHITUNGAN PORSI MBG di SPPG', null, 12);
        $this->kv('Periode', Teks::tanggalPanjang($this->d['periode']['awal']).' - '.Teks::tanggalPanjang($this->d['periode']['akhir']), 0, 28);
        $this->kv('Nama SPPG', Teks::bersih($sppg['nama'] ?? '', 100), 0, 28);
        $this->pdf->Ln(2);

        $penanda = (string) ($this->d['penyamaran']['penanda'] ?? '<5');

        foreach ((array) $this->d['tabel'] as $t) {
            $n = max(1, count($t['hari']));
            $tetap = 7 + 50 + 17 + 38;
            $perHari = ($this->pdf->lebarIsi() - $tetap) / $n;
            $wPorsi = round($perHari * 0.4, 2);
            $wBiaya = round($perHari - $wPorsi, 2);
            $lebar = [7.0, 50.0, 17.0];
            $rata = ['C', 'L', 'R'];
            $baris1 = [['t' => 'No', 'rowspan' => 2], ['t' => 'KEBUTUHAN', 'rowspan' => 2], ['t' => 'ANGGARAN', 'rowspan' => 2]];
            $baris2 = [];

            foreach ($t['hari'] as $h) {
                $lebar[] = $wPorsi;
                $lebar[] = $wBiaya;
                array_push($rata, 'R', 'R');
                $baris1[] = ['t' => Teks::tetap($h['hari'].' '.Teks::tanggalPendek($h['tanggal'])), 'span' => 2];
                array_push($baris2, ['t' => 'JUMLAH PORSI'], ['t' => 'BIAYA PER HARI']);
            }

            array_push($lebar, 14.0, 24.0);
            array_push($rata, 'R', 'R');
            $baris1[] = ['t' => 'TOTAL', 'span' => 2];
            array_push($baris2, ['t' => 'PORSI'], ['t' => 'BIAYA']);

            $this->pdf->SetX($this->pdf->margin);
            $this->pdf->butuhRuang((count($t['baris']) + 1) * 4.6 + 22);
            $this->pdf->SetFont('Helvetica', 'B', 8);
            $this->pdf->Cell(0, 4.5, Teks::tetap('Pekan mulai '.Teks::tanggalPanjang($t['pekan_mulai'])), 0, 1);
            $tabel = new TabelPdf($this->pdf, $lebar, $rata, 7, 3.8, 0.8);
            $tabel->kepala([$baris1, $baris2]);
            $no = 0;
            $nilai = fn (mixed $v, bool $uang = false): string => $v === null ? $penanda : Teks::angka($v);

            foreach ($t['baris'] as $b) {
                $no++;
                $sel = [(string) $no, Teks::bersih($b['label'], 60).($b['estimasi'] ? ' *' : ''), $b['anggaran_per_porsi'] === null ? '-' : Teks::angka($b['anggaran_per_porsi'])];

                foreach ($b['per_hari'] as $h) {
                    $sel[] = $nilai($h['porsi']);
                    $sel[] = $nilai($h['biaya']);
                }

                $sel[] = $nilai($b['total_porsi']);
                $sel[] = $nilai($b['total_biaya']);
                $tabel->baris($sel);
            }

            $total = [['t' => 'Total', 'span' => 3, 'rata' => 'L']];

            foreach ($t['total']['per_hari'] as $h) {
                $total[] = $nilai($h['porsi']);
                $total[] = $nilai($h['biaya']);
            }

            $total[] = $nilai($t['total']['total_porsi']);
            $total[] = $nilai($t['total']['total_biaya']);
            $tabel->baris($total, true, true);
            $this->pdf->Ln(4);
        }

        $this->pdf->SetX($this->pdf->margin);
        $this->pdf->teksBaris(Teks::tetap('Total periode: '.Teks::angka($this->d['total_periode']['porsi']).' porsi, biaya bahan baku acuan '.Teks::rupiah($this->d['total_periode']['biaya']).'.'), 4.2, 'B', 8);
        $this->pdf->teksBaris(Teks::tetap('* '.(string) $this->d['catatan_estimasi']), 3.8, 'I', 7);

        if (($this->d['penyamaran']['diterapkan'] ?? false) === true) {
            $this->pdf->teksBaris(Teks::tetap('Kelompok dengan penerima kurang dari '.(int) $this->d['penyamaran']['ambang_k'].' digabung/tersamar demi privasi (tanda "'.$penanda.'").'), 3.8, 'I', 7);
        }

        $this->pdf->Ln(2);
        $this->catatanSistem();
        $this->tandaTangan();
    }

    // ------------------------------------------------------------------ 30b

    private function harian(): void
    {
        $sppg = (array) $this->d['sppg'];
        $this->kop('LAPORAN HARIAN PENGGUNAAN DANA', null, 12);
        $this->kv('Nomor', $this->nomor(), 0, 30);
        $this->kv('Nama SPPG', Teks::bersih($sppg['nama'] ?? '', 100), 0, 30);
        $this->kv('Kecamatan', Teks::bersih($sppg['kecamatan'] ?? '', 100), 0, 30);
        $this->kv('Kab/Kota', Teks::bersih($sppg['kab_kota'] ?? '', 100), 0, 30);
        $this->kv('Provinsi', Teks::bersih($sppg['provinsi'] ?? '', 100), 0, 30);
        $this->kv('Tanggal', Teks::tanggalPanjang($this->d['tanggal']), 0, 30);
        $this->pdf->Ln(2);

        $s = (array) $this->d['sasaran_penerima_manfaat'];
        $this->judulBagian('Sasaran Penerima Manfaat');
        $this->sasaran('A. Peserta Didik', 'Nama Sekolah', (array) $s['peserta_didik']);
        $this->sasaran('B. Non-Peserta Didik', 'Nama Posyandu', (array) $s['non_peserta_didik']);

        $tanpa = 0;

        foreach (['peserta_didik', 'non_peserta_didik'] as $blok) {
            foreach ($s[$blok]['baris'] as $b) {
                $tanpa += (int) $b['tanpa_jenis_kelamin'];
            }
        }

        if ($tanpa > 0) {
            $this->pdf->teksBaris(Teks::tetap("Catatan: Jumlah mencakup {$tanpa} penerima yang jenis kelaminnya belum tercatat."), 3.8, 'I', 7);
        }

        $this->pdf->Ln(2);
        $r = (array) $this->d['rincian_pembelanjaan'];
        $this->judulBagian('Rincian Pembelanjaan');

        $this->subJudul('A. Bahan Baku Pangan');
        $bb = (array) $r['bahan_baku_pangan'];
        $tabel = new TabelPdf($this->pdf, [70.0, 24.0, 22.0, 32.0, 38.0], ['L', 'R', 'L', 'R', 'R']);
        $tabel->kepala([[['t' => 'Nama Bahan Baku'], ['t' => 'Jumlah'], ['t' => 'Unit Satuan'], ['t' => 'Harga Satuan'], ['t' => 'Total']]]);

        foreach ($bb['rincian'] as $b) {
            $tabel->baris([Teks::bersih($b['nama'], 60), Teks::desimal((string) $b['jumlah']), Teks::bersih($b['satuan'], 20), Teks::angka($b['harga_satuan']), Teks::angka($b['total'])]);
        }

        if ((int) $bb['bahan_baku_lainnya'] !== 0) {
            $tabel->baris([Teks::tetap('Bahan baku lainnya (tanpa rincian pembelian)'), '-', '-', '-', Teks::angka($bb['bahan_baku_lainnya'])]);
        }

        $tabel->baris([['t' => 'Total Belanja Bahan Baku Pangan', 'span' => 4, 'rata' => 'L'], Teks::angka($bb['total'])], true, true);
        $this->pdf->Ln(3);

        $this->subJudul('B. Biaya Operasional');
        $ops = (array) $r['biaya_operasional'];
        $tabel = new TabelPdf($this->pdf, [78.0, 40.0, 68.0], ['L', 'R', 'L']);
        $tabel->kepala([[['t' => 'Kebutuhan'], ['t' => 'Biaya per Hari (Rp)'], ['t' => 'Keterangan']]]);

        foreach ($ops['baris'] as $b) {
            $tabel->baris([Teks::bersih($b['label'], 80), Teks::angka($b['biaya_per_hari']), Teks::bersih($b['keterangan'] ?? '', 100)]);
        }

        $tabel->baris([['t' => 'Total Biaya Operasional', 'rata' => 'L'], Teks::angka($ops['total']), ''], true, true);
        $this->pdf->Ln(3);

        $this->subJudul('C. Biaya Insentif Fasilitas SPPG');
        $ins = (array) $r['insentif_fasilitas'];
        $tabel = new TabelPdf($this->pdf, [78.0, 40.0, 68.0], ['L', 'R', 'L']);
        $tabel->kepala([[['t' => 'Uraian'], ['t' => 'Jumlah (Rp)'], ['t' => 'Keterangan']]]);
        $tabel->baris([Teks::tetap('Biaya Insentif Fasilitas SPPG'), Teks::angka($ins['jumlah']), Teks::bersih($ins['keterangan'] ?? '', 100)]);
        $this->pdf->Ln(1);
        $this->pdf->teksBaris(Teks::tetap('Total belanja hari ini: '.Teks::rupiah($r['total_belanja']).'.'), 4.2, 'B', 8);
        $this->pdf->Ln(2);
        $this->catatanSistem();
        $this->tandaTangan();
    }

    /** @param array<string, mixed> $blok */
    private function sasaran(string $judul, string $kolomNama, array $blok): void
    {
        $this->subJudul($judul);
        $tabel = new TabelPdf($this->pdf, [66.0, 52.0, 22.0, 22.0, 24.0], ['L', 'L', 'R', 'R', 'R']);
        $tabel->kepala([[
            ['t' => 'Kelompok Penerima Manfaat', 'rowspan' => 2], ['t' => $kolomNama, 'rowspan' => 2], ['t' => 'Jenis Kelamin', 'span' => 2], ['t' => 'Jumlah', 'rowspan' => 2],
        ], [['t' => 'Jml Laki-laki'], ['t' => 'Jml Perempuan']]]);

        foreach ($blok['baris'] as $b) {
            if ($b['rincian'] === []) {
                $tabel->baris([Teks::bersih($b['label'], 80), '-', '0', '0', '0']);

                continue;
            }

            foreach ($b['rincian'] as $i => $t) {
                $tabel->baris([$i === array_key_first($b['rincian']) ? Teks::bersih($b['label'], 80) : '', Teks::bersih($t['nama'], 60), Teks::angka($t['laki_laki']), Teks::angka($t['perempuan']), Teks::angka($t['jumlah'])]);
            }
        }

        $t = (array) $blok['total'];
        $tabel->baris([['t' => 'Total', 'span' => 2, 'rata' => 'L'], Teks::angka($t['laki_laki']), Teks::angka($t['perempuan']), Teks::angka($t['jumlah'])], true, true);
        $this->pdf->Ln(3);
    }

    // ------------------------------------------------------------------ 30c, 30d-A

    private function ringkasan(bool $bulanan): void
    {
        $periode = (array) $this->d['periode'];
        $tahun = (int) $periode['tahun'];

        if ($bulanan) {
            $this->kop('LAPORAN BULANAN PENGGUNAAN DANA PROGRAM MBG TA '.$tahun, 'A. Laporan Bulanan (Summary)', 11);
        } else {
            $this->kop('LAPORAN 2 (DUA) MINGGUAN PENGGUNAAN DANA PROGRAM MBG TA '.$tahun, null, 11);
        }

        $this->kv('Nomor', $this->nomor(), 0, 30);
        $this->kv('Periode', $bulanan ? 'Bulan '.Teks::bulanTahun($periode['awal']) : Teks::tanggalPendek($periode['awal']).' s/d '.Teks::tanggalPendek($periode['akhir']), 0, 30);
        $this->pdf->Ln(3);

        $p = (array) $this->d['pelapor'];
        $this->pdf->teksBaris('Yang bertandatangan di bawah ini:', 5, '', 9.5);
        $this->pdf->Ln(1);

        foreach ([['Nama', $p['nama'] ?? null], ['Jabatan', $p['jabatan'] ?? null], ['Yayasan', $p['yayasan'] ?? null], ['SPPG', $p['sppg'] ?? null], ['ID SPPG', $p['id_sppg'] ?? null],
            ['Kecamatan', $p['kecamatan'] ?? null], ['Kab/Kota', $p['kab_kota'] ?? null], ['Provinsi', $p['provinsi'] ?? null]] as [$label, $nilai]) {
            $this->kv($label, $this->atau($nilai, $label === 'Nama' ? '(Nama Lengkap)' : '-'), 6, 30);
        }

        $this->pdf->Ln(3);
        $this->pdf->teksBaris('Dengan ini menyampaikan laporan penggunaan dana sebagai berikut:', 5, '', 9.5);
        $this->pdf->Ln(1);

        $k = (array) $this->d['rincian_keuangan'];
        $r = (array) $k['realisasi_anggaran'];
        $this->pdf->teksBaris('I. Rincian Keuangan', 5, 'B', 9.5);
        $this->kv('Saldo Awal Periode', Teks::rupiah($k['saldo_awal']), 6, 62);
        $this->kv('Dana Pemasukan - Total', Teks::rupiah($k['dana_pemasukan_total']), 6, 62);
        $this->pdf->SetX($this->pdf->margin + 6);
        $this->pdf->teksBaris('Realisasi Anggaran', 5, '', 9.5);
        $this->kv('Bahan Baku', Teks::rupiah($r['bahan_baku']), 12, 56);
        $this->kv('Operasional', Teks::rupiah($r['operasional']), 12, 56);
        $this->kv('Insentif Fasilitas SPPG', Teks::rupiah($r['insentif_fasilitas']), 12, 56);
        $this->kv('Total', Teks::rupiah($r['total']), 12, 56);
        $this->kv('Sisa Anggaran - Total', Teks::rupiah($k['sisa_anggaran_total']), 6, 62);
        $this->pdf->Ln(3);

        $ket = (array) $this->d['keterangan'];
        $this->pdf->teksBaris('II. Keterangan', 5, 'B', 9.5);
        $this->pdf->SetX($this->pdf->margin + 6);
        $this->pdf->SetFont('Helvetica', '', 9.5);
        $this->pdf->MultiCell(0, 4.8, Teks::bersih($ket['kalimat_pembuka'] ?? ''), 0, 'L');
        $this->kv('Bahan Baku', Teks::bersih($ket['bahan_baku'] ?? '', 400), 6, 56);
        $this->kv('Operasional', Teks::bersih($ket['operasional'] ?? '', 600), 6, 56);
        $this->kv('Insentif Fasilitas SPPG', Teks::bersih($ket['insentif_fasilitas'] ?? '', 400), 6, 56);
        $this->kv('No. Rekening / Virtual Account', $this->atau($ket['no_rekening_va'] ?? null, '-'), 6, 56);
        $this->pdf->Ln(3);
        $this->pdf->SetX($this->pdf->margin);
        $this->pdf->SetFont('Helvetica', '', 9.5);
        $this->pdf->MultiCell(0, 4.8, Teks::bersih($ket['sisa_dana_dialihkan'] ?? ''), 0, 'J');
        $this->pdf->Ln(3);
        $this->catatanSistem();
        $this->tandaTangan();
    }

    // ------------------------------------------------------------------ 30d-B

    private function anggaran(): void
    {
        $periode = (array) $this->d['periode'];
        $this->kop('LAPORAN BULANAN PENGGUNAAN DANA PROGRAM MBG TA '.(int) $periode['tahun'], 'B. Laporan Bulanan (Anggaran)', 11);
        $this->kv('Nomor', $this->nomor(), 0, 30);
        $this->kv('Periode', 'Bulan '.Teks::bulanTahun($periode['awal']), 0, 30);
        $this->pdf->Ln(2);

        $tabel = new TabelPdf($this->pdf, [9.0, 20.0, 52.0, 24.0, 24.0, 25.0, 32.0], ['C', 'C', 'L', 'R', 'R', 'R', 'L'], 8, 4);
        $tabel->kepala([[['t' => 'No'], ['t' => 'Tanggal'], ['t' => 'Perihal'], ['t' => 'Dana Masuk'], ['t' => 'Dana Keluar'], ['t' => 'Saldo'], ['t' => 'Keterangan']]]);

        foreach ((array) $this->d['baris'] as $b) {
            $tipe = $b['tipe'];

            if ($tipe === 'judul') {
                $tabel->baris(['', '', Teks::bersih($b['perihal']), '', '', '', ''], true);

                continue;
            }

            if ($tipe === 'subkelompok') {
                $tabel->baris(['', '', '  '.Teks::bersih($b['perihal']), '', '', '', 'Subtotal '.Teks::angka($b['subtotal'] ?? 0)], true);

                continue;
            }

            $tabel->baris([
                $b['no'] === null ? '' : (string) $b['no'],
                Teks::tanggalPendek($b['tanggal']),
                Teks::bersih($b['perihal'], (int) config('laporan-bgn.maks_teks_sel', 80)),
                $b['dana_masuk'] === null ? '' : Teks::angka($b['dana_masuk']),
                $b['dana_keluar'] === null ? '' : Teks::angka($b['dana_keluar']),
                $b['saldo'] === null ? '' : Teks::angka($b['saldo']),
                Teks::bersih($b['keterangan'] ?? '', (int) config('laporan-bgn.maks_teks_sel', 80)),
            ]);
        }

        $t = (array) $this->d['total'];
        $tabel->baris([['t' => 'Total', 'span' => 3, 'rata' => 'L'], Teks::angka($t['dana_masuk']), Teks::angka($t['dana_keluar']), Teks::angka($t['saldo_akhir']), ''], true, true);
        $this->pdf->Ln(2);
        $this->pdf->teksBaris(Teks::bersih($this->d['catatan_kaki'] ?? ''), 4, 'I', 7.5);
        $this->pdf->Ln(2);
        $this->catatanSistem();
        $this->tandaTangan();
    }

    // ------------------------------------------------------------------ 30d-C

    private function penerima(bool $lanskap): void
    {
        $periode = (array) $this->d['periode'];
        $this->kop('LAPORAN BULANAN PENGGUNAAN DANA PROGRAM MBG TA '.(int) $periode['tahun'], 'C. Laporan Bulanan (Penerima Manfaat)', 11);
        $this->kv('Bulan', Teks::bulanTahun($periode['awal']), 0, 30);
        $this->kv('Nama SPPG', Teks::bersih(((array) $this->d['sppg'])['nama'] ?? '', 100), 0, 30);
        $this->pdf->Ln(2);

        $kolom = (array) $this->d['kolom'];
        $peserta = (array) $kolom['peserta_didik'];
        $non = (array) $kolom['non_peserta_didik'];
        $belum = (int) $this->d['total_bulan']['belum_terklasifikasi'] > 0;
        $n = count($peserta) + count($non) + ($belum ? 1 : 0);
        $lebar = [5.0, 11.0, 15.0, 12.0];
        $rata = ['C', 'L', 'C', 'L'];
        $sisa = $this->pdf->lebarIsi() - array_sum($lebar) - 11.0;
        $w = round($sisa / $n, 2);

        for ($i = 0; $i < $n; $i++) {
            $lebar[] = $w;
            $rata[] = 'R';
        }

        $lebar[] = round($this->pdf->lebarIsi() - array_sum($lebar), 2);
        $rata[] = 'R';

        $baris1 = [['t' => 'No', 'rowspan' => 2], ['t' => 'Hari', 'rowspan' => 2], ['t' => 'Tanggal', 'rowspan' => 2], ['t' => 'Ket', 'rowspan' => 2],
            ['t' => 'Peserta Didik', 'span' => count($peserta)], ['t' => 'Non Peserta Didik', 'span' => count($non)]];

        if ($belum) {
            $baris1[] = ['t' => 'Belum terklasifikasi', 'rowspan' => 2];
        }

        $baris1[] = ['t' => 'Total', 'rowspan' => 2];
        $baris2 = array_map(fn (array $k) => ['t' => Teks::bersih($k['label'])], array_merge($peserta, $non));

        $tabel = new TabelPdf($this->pdf, $lebar, $rata, $lanskap ? 7.5 : 5.8, $lanskap ? 4.2 : 3.3, $lanskap ? 1.0 : 0.6, $lanskap ? 6.5 : 4.6);
        $tabel->kepala([$baris1, $baris2]);
        $no = 0;

        foreach ((array) $this->d['hari'] as $h) {
            $sel = [(string) ++$no, Teks::tetap($h['hari']), Teks::tanggalPendek($h['tanggal']), Teks::tetap(self::KET[$h['ket']] ?? '-')];

            foreach (array_merge($h['peserta_didik'], $h['non_peserta_didik']) as $k) {
                $sel[] = Teks::angka($k['porsi']);
            }

            if ($belum) {
                $sel[] = Teks::angka($h['belum_terklasifikasi']);
            }

            $sel[] = Teks::angka($h['total']);
            $tabel->baris($sel);
        }

        $t = (array) $this->d['total_bulan'];
        $sel = [['t' => 'Total', 'span' => 4, 'rata' => 'L']];

        foreach (array_merge($t['peserta_didik'], $t['non_peserta_didik']) as $k) {
            $sel[] = Teks::angka($k['porsi']);
        }

        if ($belum) {
            $sel[] = Teks::angka($t['belum_terklasifikasi']);
        }

        $sel[] = Teks::angka($t['total']);
        $tabel->baris($sel, true, true);
        $this->pdf->Ln(2);
        $this->pdf->SetX($this->pdf->margin);
        $this->pdf->teksBaris(Teks::tetap('* '.(string) $this->d['catatan_estimasi']), 3.8, 'I', 7);
        $this->pdf->Ln(1);
        $this->catatanSistem();
        $this->tandaTangan();
    }

    // ------------------------------------------------------------------ lampiran bukti

    /** @param iterable<array{label: string, jpeg: string|null}> $bukti */
    private function lampiran(iterable $bukti, int $dilewati): void
    {
        $i = 0;
        $w = $this->pdf->lebarIsi();
        $tinggiKotak = 132.0;

        foreach ($bukti as $b) {
            if ($i % 2 === 0) {
                $this->pdf->halaman();

                if ($i === 0) {
                    $this->pdf->SetFont('Helvetica', 'B', 11);
                    $this->pdf->Cell(0, 6, 'LAMPIRAN BUKTI', 0, 1, 'C');
                    $this->pdf->SetFont('Helvetica', 'I', 7.5);
                    $this->pdf->MultiCell(0, 3.8, Teks::tetap('Foto nota/bukti di bawah dilampirkan terpisah dari format laporan BGN; diperkecil untuk keperluan cetak.'), 0, 'C');
                    $this->pdf->Ln(2);
                }
            }

            $i++;
            $y = $this->pdf->GetY();
            $this->pdf->SetFont('Helvetica', 'B', 9);
            $this->pdf->SetXY($this->pdf->margin, $y);
            $this->pdf->MultiCell(0, 4.5, Teks::bersih('Bukti '.$i.': '.$b['label'], 160), 0, 'L');
            $y = $this->pdf->GetY() + 1;

            if ($b['jpeg'] === null || ! $this->gambar((string) $b['jpeg'], $y, $w, $tinggiKotak)) {
                $this->pdf->SetFont('Helvetica', 'I', 8);
                $this->pdf->SetXY($this->pdf->margin, $y);
                $this->pdf->Cell(0, 5, '(foto bukti tidak dapat ditampilkan)', 0, 1);
                $this->pdf->SetY($y + 8);
            } else {
                $this->pdf->SetY($y + $tinggiKotak + 4);
            }
        }

        if ($dilewati > 0 && $i > 0) {
            $this->pdf->SetFont('Helvetica', 'I', 8);
            $this->pdf->SetX($this->pdf->margin);
            $this->pdf->MultiCell(0, 4, Teks::tetap("{$dilewati} bukti lain tidak disertakan (batas ".(int) config('laporan-bgn.maks_bukti', 20).' foto per berkas).'), 0, 'L');
        }
    }

    private function gambar(string $jpeg, float $y, float $lebar, float $tinggi): bool
    {
        $info = @getimagesizefromstring($jpeg);

        if ($info === false || ($info[2] ?? 0) !== IMAGETYPE_JPEG || $info[0] < 1 || $info[1] < 1) {
            return false;
        }

        $skala = min($lebar / $info[0], $tinggi / $info[1]);
        $w = $info[0] * $skala;
        $h = $info[1] * $skala;
        $dir = dirname($this->tempDasar());
        $berkas = tempnam(is_dir($dir) ? $dir : sys_get_temp_dir(), 'bkt');

        if ($berkas === false) {
            return false;
        }

        try {
            file_put_contents($berkas, $jpeg);
            $this->pdf->Image($berkas, $this->pdf->margin + ($lebar - $w) / 2, $y, $w, $h, 'JPG');
        } finally {
            @unlink($berkas);
        }

        return true;
    }

    private function tempDasar(): string
    {
        return rtrim(storage_path((string) config('laporan-bgn.direktori_kerja', 'app/private/laporan-bgn-tmp')), '/\\').DIRECTORY_SEPARATOR.'x';
    }

    // ------------------------------------------------------------------ bagian bersama

    private function kop(string $judul, ?string $sub, float $ukuran): void
    {
        $this->pdf->SetFont('Helvetica', 'B', $ukuran);
        $this->pdf->MultiCell(0, $ukuran * 0.5, Teks::tetap($judul), 0, 'C');

        if ($sub !== null) {
            $this->pdf->SetFont('Helvetica', 'B', $ukuran - 1);
            $this->pdf->MultiCell(0, 5, Teks::tetap($sub), 0, 'C');
        }

        $this->pdf->Ln(3);
    }

    private function kv(string $label, string $nilai, float $indent = 0, float $lebarLabel = 30): void
    {
        $this->pdf->SetFont('Helvetica', '', 9.5);
        $this->pdf->butuhRuang(6);
        $this->pdf->SetX($this->pdf->margin + $indent);
        $this->pdf->Cell($lebarLabel, 4.8, Teks::tetap($label), 0, 0);
        $this->pdf->Cell(3, 4.8, ':', 0, 0);
        $this->pdf->MultiCell(0, 4.8, $nilai, 0, 'L');
    }

    private function judulBagian(string $teks): void
    {
        $this->pdf->SetFont('Helvetica', 'B', 10);
        $this->pdf->Cell(0, 5.5, Teks::tetap($teks), 0, 1);
    }

    private function subJudul(string $teks): void
    {
        $this->pdf->butuhRuang(20);
        $this->pdf->SetFont('Helvetica', 'B', 9);
        $this->pdf->SetX($this->pdf->margin);
        $this->pdf->Cell(0, 5, Teks::tetap($teks), 0, 1);
    }

    private function nomor(): string
    {
        $n = $this->d['nomor'] ?? null;

        return $n === null || $n === '' ? '(belum diterbitkan)' : Teks::bersih($n, 60);
    }

    private function atau(mixed $nilai, string $cadangan): string
    {
        $s = Teks::bersih($nilai, 120);

        return $s === '' ? Teks::tetap($cadangan) : $s;
    }

    /** Peringatan sistem (mis. saldo awal belum diisi) dicetak kecil agar pembaca tahu keterbatasan data. */
    private function catatanSistem(): void
    {
        $daftar = (array) ($this->d['peringatan'] ?? []);

        if ($daftar === []) {
            return;
        }

        $this->pdf->SetX($this->pdf->margin);
        $this->pdf->SetFont('Helvetica', 'I', 7);
        $this->pdf->SetTextColor(120, 40, 40);

        foreach ($daftar as $p) {
            $this->pdf->SetX($this->pdf->margin);
            $this->pdf->MultiCell(0, 3.6, Teks::bersih('Catatan sistem: '.($p['pesan'] ?? ''), 220), 0, 'L');
        }

        $this->pdf->SetTextColor(0, 0, 0);
        $this->pdf->Ln(2);
    }

    /** Pihak Pertama (kiri), Pihak Kedua (kanan), Mengetahui (tengah bawah); blok tidak terbelah halaman. */
    private function tandaTangan(): void
    {
        $p = (array) $this->d['penandatangan'];
        $sppg = (array) $this->d['sppg'];
        $this->pdf->butuhRuang(66);
        $m = $this->pdf->margin;
        $lebar = $this->pdf->lebarIsi();
        $kolom = $lebar / 2;
        $y = $this->pdf->GetY() + 2;

        $baris = function (float $x, float $y, float $w, string $teks, string $gaya = '', float $h = 4.5): float {
            $this->pdf->SetFont('Helvetica', $gaya, 9);
            $this->pdf->SetXY($x, $y);
            $this->pdf->MultiCell($w, $h, $teks, 0, 'C');

            return $this->pdf->GetY();
        };

        $kota = Teks::bersih($p['kota'] ?? '', 60);
        $tanggal = Teks::tanggalPanjang($p['tanggal'] ?? null);
        $baris($m + $kolom, $y, $kolom, trim(($kota !== '' ? $kota : '(Kab/Kota)').', '.$tanggal), '');
        $y += 7;

        $kiri = (array) $p['pihak_pertama'];
        $kanan = (array) $p['pihak_kedua'];
        $tengah = (array) $p['mengetahui'];
        $nama = fn (mixed $n): string => $this->atau($n, '(Nama Lengkap)');

        $a = $baris($m, $y, $kolom, 'Pihak Pertama', 'B');
        $a = $baris($m, $a, $kolom, $this->atau($kiri['yayasan'] ?? null, '(Nama Yayasan)'));
        $b = $baris($m + $kolom, $y, $kolom, 'Pihak Kedua', 'B');
        $b = $baris($m + $kolom, $b, $kolom, Teks::tetap((string) ($kanan['jabatan'] ?? '')).' '.Teks::bersih($sppg['nama'] ?? '', 60));
        $yNama = max($a, $b) + 20;
        $a = $baris($m, $yNama, $kolom, $nama($kiri['nama'] ?? null), 'B');
        $baris($m, $a, $kolom, Teks::tetap((string) ($kiri['jabatan'] ?? '')));
        $b = $baris($m + $kolom, $yNama, $kolom, $nama($kanan['nama'] ?? null), 'B');

        $yTengah = max($a, $b) + 8;
        $c = $baris($m + $kolom / 2, $yTengah, $kolom, 'Mengetahui', 'B');
        $c = $baris($m + $kolom / 2, $c, $kolom, Teks::tetap((string) ($tengah['jabatan'] ?? '')).' '.Teks::bersih($sppg['nama'] ?? '', 60));
        $c = $baris($m + $kolom / 2, $c + 20, $kolom, $nama($tengah['nama'] ?? null), 'B');
        $this->pdf->SetY($c + 2);
    }
}
