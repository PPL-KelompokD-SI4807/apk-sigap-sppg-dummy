<?php

namespace App\Support;

/**
 * Label browser ringkas dari User-Agent (mis. "Firefox · Linux") untuk nama sesi web dan pesan bot login web
 * (R04-2). Hanya nama keluarga browser + OS dari daftar tetap: tidak pernah menyalin isi User-Agent mentah
 * (bisa dipalsukan/berisi teks sembarang) ke pesan bot atau basis data.
 */
final class LabelBrowser
{
    /** Urutan penting: token yang lebih spesifik lebih dulu (Edge/Opera/Samsung memuat "Chrome"). */
    private const BROWSER = [
        'Edg/' => 'Edge',
        'EdgA/' => 'Edge',
        'OPR/' => 'Opera',
        'SamsungBrowser/' => 'Samsung Internet',
        'Firefox/' => 'Firefox',
        'FxiOS/' => 'Firefox',
        'CriOS/' => 'Chrome',
        'Chrome/' => 'Chrome',
        'Safari/' => 'Safari',
    ];

    private const OS = [
        'Android' => 'Android',
        'iPhone' => 'iOS',
        'iPad' => 'iPadOS',
        'CrOS' => 'ChromeOS',
        'Windows' => 'Windows',
        'Macintosh' => 'macOS',
        'Mac OS X' => 'macOS',
        'Linux' => 'Linux',
    ];

    public static function dari(?string $userAgent): string
    {
        $ua = (string) $userAgent;
        $browser = self::cari($ua, self::BROWSER);
        $os = self::cari($ua, self::OS);

        return match (true) {
            $browser !== null && $os !== null => $browser.' · '.$os,
            $browser !== null => $browser,
            $os !== null => 'Browser · '.$os,
            default => 'Browser',
        };
    }

    /** @param array<string, string> $peta */
    private static function cari(string $ua, array $peta): ?string
    {
        foreach ($peta as $penanda => $nama) {
            if (str_contains($ua, $penanda)) {
                return $nama;
            }
        }

        return null;
    }
}
