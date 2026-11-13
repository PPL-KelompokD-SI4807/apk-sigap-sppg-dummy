<?php

namespace App\Services\LaporanBgn\Pdf;

/**
 * Helper tabel berborder untuk FPDF: lebar kolom tetap (mm), teks dibungkus, kepala bertingkat (colspan/rowspan),
 * pemotongan halaman dengan kepala berulang. Semua teks yang masuk harus sudah melewati Teks::bersih().
 */
final class TabelPdf
{
    /** @var list<list<array{t: string, span?: int, rowspan?: int}>> */
    private array $kepala = [];

    /** @var list<float> tinggi tiap baris kepala (mm) */
    private array $tinggiKepala = [];

    private float $x0;

    /**
     * @param  list<float>  $lebar  lebar kolom (mm)
     * @param  list<string>  $rata  'L'|'C'|'R' per kolom
     */
    public function __construct(
        private readonly LaporanPdf $pdf,
        private readonly array $lebar,
        private readonly array $rata,
        private readonly float $ukuran = 8.5,
        private readonly float $tinggiBaris = 4.2,
        private readonly float $pad = 1.1,
        private readonly ?float $ukuranKepala = null,
    ) {
        $this->x0 = $pdf->GetX();
    }

    /** @param list<list<array{t: string, span?: int, rowspan?: int}>> $baris */
    public function kepala(array $baris): self
    {
        $this->kepala = $baris;
        $this->hitungKepala();
        $this->pdf->butuhRuang(array_sum($this->tinggiKepala) + $this->tinggiBaris * 2);
        $this->x0 = $this->pdf->GetX();
        $this->gambarKepala();

        return $this;
    }

    /**
     * @param  list<string|array{t: string, span?: int, rata?: string}>  $sel
     */
    public function baris(array $sel, bool $tebal = false, bool $isi = false): void
    {
        $this->pdf->SetFont('Helvetica', $tebal ? 'B' : '', $this->ukuran);
        $spek = [];
        $kolom = 0;

        foreach ($sel as $s) {
            $s = is_array($s) ? $s : ['t' => $s];
            $span = max(1, (int) ($s['span'] ?? 1));
            $w = array_sum(array_slice($this->lebar, $kolom, $span));
            $spek[] = ['kolom' => $kolom, 'w' => $w, 'baris' => $this->bungkus((string) $s['t'], $w), 'rata' => $s['rata'] ?? $this->rata[$kolom] ?? 'L'];
            $kolom += $span;
        }

        $maks = 1;

        foreach ($spek as $c) {
            $maks = max($maks, count($c['baris']));
        }

        $h = $maks * $this->tinggiBaris + $this->pad;

        if ($this->pdf->sisaRuang() < $h) {
            $this->pdf->halaman();
            $this->pdf->SetX($this->x0);

            if ($this->kepala !== []) {
                $this->gambarKepala();
            }

            $this->pdf->SetFont('Helvetica', $tebal ? 'B' : '', $this->ukuran);
        }

        $y = $this->pdf->GetY();

        if ($isi) {
            $this->pdf->SetFillColor(235, 235, 235);
        }

        foreach ($spek as $c) {
            $xs = $this->x0 + array_sum(array_slice($this->lebar, 0, $c['kolom']));
            $this->pdf->Rect($xs, $y, $c['w'], $h, $isi ? 'DF' : 'D');
            $this->tulisSel($xs, $y + $this->pad / 2, $c['w'], $c['baris'], $c['rata']);
        }

        $this->pdf->SetXY($this->x0, $y + $h);
    }

    public function lebarTotal(): float
    {
        return array_sum($this->lebar);
    }

    // ------------------------------------------------------------------ internal

    /** @param list<string> $baris */
    private function tulisSel(float $x, float $y, float $w, array $baris, string $rata): void
    {
        foreach ($baris as $i => $b) {
            $this->pdf->SetXY($x + $this->pad, $y + $i * $this->tinggiBaris);
            $this->pdf->Cell($w - 2 * $this->pad, $this->tinggiBaris, $b, 0, 0, $rata);
        }
    }

    private function hitungKepala(): void
    {
        $this->pdf->SetFont('Helvetica', 'B', $this->ukuranKepala ?? $this->ukuran);
        $tinggi = array_fill(0, count($this->kepala), $this->tinggiBaris + $this->pad);
        $peta = $this->petaKepala();

        foreach ($peta as $c) {
            if ($c['rowspan'] === 1) {
                $perlu = count($this->bungkus($c['t'], $c['w'])) * $this->tinggiBaris + $this->pad;
                $tinggi[$c['r']] = max($tinggi[$c['r']], $perlu);
            }
        }

        foreach ($peta as $c) {
            if ($c['rowspan'] > 1) {
                $perlu = count($this->bungkus($c['t'], $c['w'])) * $this->tinggiBaris + $this->pad;
                $ada = array_sum(array_slice($tinggi, $c['r'], $c['rowspan']));

                if ($perlu > $ada) {
                    $tinggi[$c['r'] + $c['rowspan'] - 1] += $perlu - $ada;
                }
            }
        }

        $this->tinggiKepala = $tinggi;
    }

    /** @return list<array{t: string, r: int, c: int, span: int, rowspan: int, w: float}> */
    private function petaKepala(): array
    {
        $terisi = [];
        $hasil = [];

        foreach ($this->kepala as $r => $baris) {
            $c = 0;

            foreach ($baris as $sel) {
                while (isset($terisi[$r][$c])) {
                    $c++;
                }

                $span = max(1, (int) ($sel['span'] ?? 1));
                $rs = max(1, (int) ($sel['rowspan'] ?? 1));

                for ($i = 0; $i < $rs; $i++) {
                    for ($j = 0; $j < $span; $j++) {
                        $terisi[$r + $i][$c + $j] = true;
                    }
                }

                $hasil[] = ['t' => (string) $sel['t'], 'r' => $r, 'c' => $c, 'span' => $span, 'rowspan' => $rs, 'w' => array_sum(array_slice($this->lebar, $c, $span))];
                $c += $span;
            }
        }

        return $hasil;
    }

    private function gambarKepala(): void
    {
        $this->pdf->SetFont('Helvetica', 'B', $this->ukuranKepala ?? $this->ukuran);
        $this->pdf->SetFillColor(215, 215, 215);
        $y0 = $this->pdf->GetY();

        foreach ($this->petaKepala() as $c) {
            $x = $this->x0 + array_sum(array_slice($this->lebar, 0, $c['c']));
            $y = $y0 + array_sum(array_slice($this->tinggiKepala, 0, $c['r']));
            $h = array_sum(array_slice($this->tinggiKepala, $c['r'], $c['rowspan']));
            $this->pdf->Rect($x, $y, $c['w'], $h, 'DF');
            $baris = $this->bungkus($c['t'], $c['w']);
            $mulai = $y + max($this->pad / 2, ($h - count($baris) * $this->tinggiBaris) / 2);
            $this->tulisSel($x, $mulai, $c['w'], $baris, 'C');
        }

        $this->pdf->SetXY($this->x0, $y0 + array_sum($this->tinggiKepala));
    }

    /**
     * Bungkus teks (cp1252, font aktif) ke lebar sel; kata terlalu panjang dipecah per karakter.
     *
     * @return list<string>
     */
    private function bungkus(string $teks, float $w): array
    {
        $maks = $w - 2 * $this->pad;
        $hasil = [];

        foreach (explode("\n", $teks) as $para) {
            $baris = '';

            foreach (preg_split('/ +/', $para) ?: [''] as $kata) {
                while ($kata !== '' && $this->pdf->GetStringWidth($kata) > $maks) {
                    $n = 1;

                    while ($n < strlen($kata) && $this->pdf->GetStringWidth(substr($kata, 0, $n + 1)) <= $maks) {
                        $n++;
                    }

                    if ($baris !== '') {
                        $hasil[] = $baris;
                        $baris = '';
                    }

                    $hasil[] = substr($kata, 0, $n);
                    $kata = substr($kata, $n);
                }

                $coba = $baris === '' ? $kata : $baris.' '.$kata;

                if ($baris !== '' && $this->pdf->GetStringWidth($coba) > $maks) {
                    $hasil[] = $baris;
                    $baris = $kata;
                } else {
                    $baris = $coba;
                }
            }

            $hasil[] = $baris;
        }

        return $hasil;
    }
}
