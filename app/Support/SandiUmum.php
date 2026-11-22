<?php

namespace App\Support;

/**
 * Daftar sandi umum LOKAL (config('kredensial.daftar_sandi_umum')). Tidak memanggil layanan
 * eksternal. Pencocokan tanpa membedakan huruf besar/kecil, utuh maupun tanpa angka/simbol
 * (mis. "Sigap2026!" -> "sigap"), sehingga variasi "Password1" juga ditolak.
 */
final class SandiUmum
{
    /** @var array<string, true>|null */
    private static ?array $cache = null;

    private static ?string $cachePath = null;

    public static function termasuk(string $password): bool
    {
        $daftar = self::daftar();
        $kecil = mb_strtolower($password);
        $huruf = (string) preg_replace('/[^\p{L}]/u', '', $kecil);

        return isset($daftar[$kecil]) || ($huruf !== '' && isset($daftar[$huruf]));
    }

    /** @return array<string, true> */
    private static function daftar(): array
    {
        $path = (string) config('kredensial.daftar_sandi_umum');

        if (self::$cache !== null && self::$cachePath === $path) {
            return self::$cache;
        }

        $baris = is_file($path) ? (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
        $daftar = [];

        foreach ($baris as $entri) {
            $entri = mb_strtolower(trim($entri));

            if ($entri !== '' && ! str_starts_with($entri, '#')) {
                $daftar[$entri] = true;
            }
        }

        self::$cachePath = $path;

        return self::$cache = $daftar;
    }
}
