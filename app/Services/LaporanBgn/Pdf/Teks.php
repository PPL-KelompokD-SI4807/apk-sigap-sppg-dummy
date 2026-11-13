<?php

namespace App\Services\LaporanBgn\Pdf;

/**
 * Pemformat teks dan angka untuk PDF. Seluruh teks pengguna melewati Teks::bersih(): UTF-8 dipulihkan, karakter
 * kendali dibuang, dipotong, lalu dikonversi ke cp1252 (font inti FPDF); karakter di luar cp1252 menjadi "?".
 */
final class Teks
{
    private const BULAN = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    public static function bersih(mixed $nilai, int $maks = 0): string
    {
        $s = is_scalar($nilai) ? (string) $nilai : '';
        $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        $s = preg_replace('/[\x00-\x1F\x7F\x{0080}-\x{009F}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2028}\x{2029}\x{FEFF}]+/u', ' ', $s) ?? '';
        $s = trim(preg_replace('/ {2,}/', ' ', $s) ?? '');

        if ($maks > 0 && mb_strlen($s) > $maks) {
            $s = rtrim(mb_substr($s, 0, max(1, $maks - 3))).'...';
        }

        return self::kePdf($s);
    }

    /** Teks tetap (literal kode, aman) tanpa potong; tetap dikonversi ke cp1252. */
    public static function tetap(string $s): string
    {
        return self::kePdf($s);
    }

    private static function kePdf(string $utf8): string
    {
        $sebelumnya = mb_substitute_character();
        mb_substitute_character(0x3F);
        $hasil = mb_convert_encoding($utf8, 'Windows-1252', 'UTF-8');
        mb_substitute_character($sebelumnya);

        return $hasil;
    }

    public static function angka(int|float|null $n): string
    {
        return $n === null ? '' : number_format((float) $n, 0, ',', '.');
    }

    public static function rupiah(int|float|null $n): string
    {
        return $n === null ? '' : 'Rp'.number_format((float) $n, 0, ',', '.');
    }

    /** Jumlah desimal string bcmath (mis. "10.500") -> "10,5". */
    public static function desimal(string $n): string
    {
        $n = str_contains($n, '.') ? rtrim(rtrim($n, '0'), '.') : $n;
        [$bulat, $pecah] = array_pad(explode('.', $n, 2), 2, '');
        $bulat = number_format((float) $bulat, 0, ',', '.');

        return $pecah === '' ? $bulat : $bulat.','.$pecah;
    }

    /** 2026-11-02 -> "2 November 2026". */
    public static function tanggalPanjang(?string $ymd): string
    {
        if ($ymd === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m) !== 1) {
            return '';
        }

        return (int) $m[3].' '.self::BULAN[(int) $m[2]].' '.$m[1];
    }

    /** 2026-11-02 -> "02-11-2026". */
    public static function tanggalPendek(?string $ymd): string
    {
        if ($ymd === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m) !== 1) {
            return '';
        }

        return $m[3].'-'.$m[2].'-'.$m[1];
    }

    public static function bulanTahun(?string $ymd): string
    {
        if ($ymd === null || preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $ymd, $m) !== 1) {
            return '';
        }

        return self::BULAN[(int) $m[2]].' '.$m[1];
    }
}
