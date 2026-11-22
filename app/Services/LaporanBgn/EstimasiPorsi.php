<?php

namespace App\Services\LaporanBgn;

/**
 * Estimasi porsi per kelompok (Q-3 R-07): porsi harian dibagi menurut proporsi penerima aktif per kelompok dengan
 * metode sisa terbesar, sehingga jumlah hasil SELALU sama dengan porsi harian (laporan dapat direkonsiliasi).
 * Hasil bersifat estimasi: SiGAP tidak mencatat porsi per kelompok per hari.
 */
final class EstimasiPorsi
{
    /**
     * @param  array<string, int>  $jumlah  kunci kelompok => jumlah penerima aktif (urutan kunci = urutan tie-break)
     * @return array<string, int> kunci sama => porsi estimasi
     */
    public static function bagi(int $porsi, array $jumlah): array
    {
        $hasil = array_fill_keys(array_keys($jumlah), 0);
        $total = array_sum($jumlah);

        if ($porsi <= 0 || $total <= 0) {
            return $hasil;
        }

        $sisa = [];
        $terbagi = 0;

        foreach ($jumlah as $kunci => $n) {
            $hasil[$kunci] = intdiv($porsi * $n, $total);
            $sisa[$kunci] = ($porsi * $n) % $total;
            $terbagi += $hasil[$kunci];
        }

        // Sisa terbesar mendapat tambahan; seri diputus urutan kunci (deterministik).
        $urutan = array_keys($jumlah);
        usort($urutan, fn (string $a, string $b) => [$sisa[$b], array_search($a, array_keys($jumlah), true)] <=> [$sisa[$a], array_search($b, array_keys($jumlah), true)]);

        for ($i = 0; $i < $porsi - $terbagi; $i++) {
            $hasil[$urutan[$i]]++;
        }

        return $hasil;
    }
}
