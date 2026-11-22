<?php

namespace App\Services\Telegram\Bot\Layar;

use Carbon\Carbon;

/**
 * Pemformat nilai untuk layar baca Bot (teks BIASA; pemanggil yang meng-escape HTML). Tidak menerima objek
 * model: hanya nilai skalar dari kolom whitelist hasil BotLayarService.
 */
final class FormatLayar
{
    public static function zona(): string
    {
        return (string) config('operasional.timezone', 'Asia/Jakarta');
    }

    public static function hariIni(): string
    {
        return Carbon::now(self::zona())->toDateString();
    }

    public static function rupiah(mixed $nilai): string
    {
        return 'Rp '.number_format((float) $nilai, 0, ',', '.');
    }

    public static function angka(mixed $nilai, int $desimal = 0): string
    {
        if ($nilai === null || $nilai === '') {
            return '-';
        }

        $teks = number_format((float) $nilai, $desimal > 0 ? $desimal : 2, ',', '.');

        // Tanpa desimal tetap: buang nol di belakang koma (2,50 -> 2,5; 3,00 -> 3).
        return $desimal > 0 ? $teks : rtrim(rtrim($teks, '0'), ',');
    }

    /** 'Y-m-d' atau 'Y-m-d H:i' -> 'd/m/Y' atau 'd/m H:i'. */
    public static function tanggal(mixed $nilai): string
    {
        if (! is_string($nilai) || $nilai === '') {
            return '-';
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}:\d{2}))?/', $nilai, $m) !== 1) {
            return '-';
        }

        return isset($m[4]) ? "{$m[3]}/{$m[2]} {$m[4]}" : "{$m[3]}/{$m[2]}/{$m[1]}";
    }

    /** Hanya tanggal ('Y-m-d' atau 'Y-m-d H:i') -> 'd/m/Y'. */
    public static function hari(mixed $nilai): string
    {
        return is_string($nilai) ? self::tanggal(substr($nilai, 0, 10)) : '-';
    }

    public static function jam(mixed $nilai): string
    {
        return is_string($nilai) && preg_match('/^\d{2}:\d{2}/', $nilai) === 1 ? substr($nilai, 0, 5) : '-';
    }

    /** 'perlu_revisi' -> 'Perlu revisi'. */
    public static function label(mixed $nilai): string
    {
        return is_string($nilai) && $nilai !== '' ? ucfirst(str_replace('_', ' ', $nilai)) : '-';
    }

    public static function ya(mixed $nilai): string
    {
        return $nilai ? 'ya' : 'tidak';
    }

    /**
     * Teks bebas pendek: spasi dirapikan, dipotong, dan '@' dibuang agar tidak menjadi sebutan pengguna Telegram.
     * (Isi sudah tersamar oleh RecordPresenter; ini hanya pengaman tampilan.)
     */
    public static function teks(mixed $nilai, int $maks = 100): string
    {
        if (! is_string($nilai) || trim($nilai) === '') {
            return '-';
        }

        $bersih = trim((string) preg_replace('/\s+/u', ' ', str_replace('@', '', $nilai)));

        return mb_strlen($bersih) > $maks ? rtrim(mb_substr($bersih, 0, $maks)).'…' : $bersih;
    }

    /** Nilai teks pendek (nama, plat): tanpa '@', maks. 60 karakter, '-' bila kosong. */
    public static function nama(mixed $nilai): string
    {
        return self::teks($nilai, 60);
    }
}
