<?php

namespace App\Services\Dashboard\Widget;

/** Pembantu kecil bersama kelas widget. */
trait Format
{
    /**
     * Setiap widget memuat `periode` (hari ini / bulan berjalan / tren / saat ini) di kunci pertama.
     *
     * @param  array<string, mixed>  $periode
     * @param  array<string, mixed>  $isi
     * @return array<string, mixed>
     */
    protected function widget(array $periode, array $isi): array
    {
        return ['periode' => $periode] + $isi;
    }

    protected function persen(int|float $bagian, int|float $total): ?float
    {
        return $total > 0 ? round($bagian * 100 / $total, 2) : null;
    }

    /** Rupiah bulat dari nilai DB (string desimal, int, atau float) tanpa aritmetika float berantai. */
    protected function rupiah(mixed $nilai): int
    {
        if ($nilai === null || $nilai === '') {
            return 0;
        }

        if (is_int($nilai)) {
            return $nilai;
        }

        if (is_float($nilai)) {
            return (int) round($nilai);
        }

        $teks = trim((string) $nilai);
        $negatif = str_starts_with($teks, '-');
        [$bulat, $pecahan] = array_pad(explode('.', ltrim($teks, '-+'), 2), 2, '');
        $hasil = (int) ($bulat === '' ? '0' : $bulat);

        if ($pecahan !== '' && (int) $pecahan[0] >= 5) {
            $hasil++;
        }

        return $negatif ? -$hasil : $hasil;
    }

    /** Kunci hari Y-m-d dari nilai DATE/DATETIME mentah. */
    protected function hari(mixed $nilai): string
    {
        return substr((string) $nilai, 0, 10);
    }
}
