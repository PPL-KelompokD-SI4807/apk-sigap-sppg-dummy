<?php

namespace App\Services\Ai\Evaluasi;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Penilai akurasi satu kasus evaluasi Agen AI (NFR-11). Fungsi murni tanpa akses basis data/jaringan.
 *
 * - Tool benar: nama tool yang dipilih model sama dengan yang diharapkan.
 * - Akurasi kolom: jumlah kunci argumen yang diharapkan yang nilainya cocok / jumlah kunci yang diharapkan.
 *   Tool salah = tidak ada kolom yang dihitung cocok (argumen tool lain tidak bermakna).
 * - Normalisasi: teks (trim, huruf kecil, spasi ganda dirapikan), angka (dibandingkan secara numerik, "50" = 50.0),
 *   boolean (true/false, "true"/"ya"/"1"), tanggal Y-m-d, jam H:i, larik/objek rekursif (hanya kunci yang diharapkan).
 */
final class PenilaiKasus
{
    /**
     * @param  array<string, mixed>  $diharapkan
     * @param  array<string, mixed>  $aktual
     * @return array{tool_benar: bool, kolom: array<string, bool>, kolom_cocok: int, kolom_total: int}
     */
    public static function nilai(string $toolDiharapkan, array $diharapkan, ?string $toolDipilih, array $aktual): array
    {
        $toolBenar = $toolDipilih !== null && $toolDipilih === $toolDiharapkan;
        $kolom = [];

        foreach ($diharapkan as $kunci => $nilai) {
            $kolom[(string) $kunci] = $toolBenar
                && array_key_exists($kunci, $aktual)
                && self::sama($nilai, $aktual[$kunci]);
        }

        return [
            'tool_benar' => $toolBenar,
            'kolom' => $kolom,
            'kolom_cocok' => count(array_filter($kolom)),
            'kolom_total' => count($kolom),
        ];
    }

    public static function sama(mixed $diharapkan, mixed $aktual): bool
    {
        if (is_array($diharapkan)) {
            if (! is_array($aktual)) {
                return false;
            }

            if (array_is_list($diharapkan)) {
                if (! array_is_list($aktual) || count($aktual) !== count($diharapkan)) {
                    return false;
                }

                foreach ($diharapkan as $i => $item) {
                    if (! self::sama($item, $aktual[$i])) {
                        return false;
                    }
                }

                return true;
            }

            foreach ($diharapkan as $kunci => $item) {
                if (! array_key_exists($kunci, $aktual) || ! self::sama($item, $aktual[$kunci])) {
                    return false;
                }
            }

            return true;
        }

        if ($diharapkan === null) {
            return $aktual === null || $aktual === '';
        }

        if (is_bool($diharapkan)) {
            return self::bool($aktual) === $diharapkan;
        }

        if (is_int($diharapkan) || is_float($diharapkan)) {
            return is_numeric($aktual) && abs((float) $aktual - (float) $diharapkan) < 1e-6;
        }

        if (! is_string($diharapkan) || (! is_string($aktual) && ! is_int($aktual) && ! is_float($aktual))) {
            return false;
        }

        $aktual = (string) $aktual;

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $diharapkan) === 1) {
            return self::tanggal($aktual) === $diharapkan;
        }

        if (preg_match('/^\d{2}:\d{2}$/', $diharapkan) === 1) {
            return preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($aktual), $m) === 1
                && sprintf('%02d:%s', (int) $m[1], $m[2]) === $diharapkan;
        }

        return self::teks($diharapkan) === self::teks($aktual);
    }

    public static function teks(string $nilai): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $nilai)));
    }

    private static function bool(mixed $nilai): ?bool
    {
        if (is_bool($nilai)) {
            return $nilai;
        }

        if (is_int($nilai) && ($nilai === 0 || $nilai === 1)) {
            return $nilai === 1;
        }

        if (is_string($nilai)) {
            return match (self::teks($nilai)) {
                'true', 'ya', '1' => true,
                'false', 'tidak', '0' => false,
                default => null,
            };
        }

        return null;
    }

    private static function tanggal(string $nilai): ?string
    {
        $nilai = trim($nilai);

        if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:[T ].*)?$/', $nilai, $m) !== 1) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $m[1])->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }
}
