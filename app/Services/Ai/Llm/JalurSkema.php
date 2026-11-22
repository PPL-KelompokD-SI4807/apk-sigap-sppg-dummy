<?php

namespace App\Services\Ai\Llm;

/**
 * Penyaring JSON pointer argumen tool dari pesan galat penyedia (mis. `/bahan/0/bahan_baku_id`) sebelum masuk log
 * (`ai.llm_galat`) atau catatan perbaikan ke model. Satu aturan untuk kedua tempat:
 * - segmen PERTAMA wajib nama properti (huruf/garis bawah di depan) dan, bila daftar nama diberikan, nama properti
 *   skema tool itu;
 * - segmen berikutnya: nama properti skema, atau indeks array \d{1,2} (maxItems skema <= 50).
 * Deretan angka panjang (NIK, nomor telepon yang mungkin diulang penyedia di error.message) tidak pernah lolos.
 */
final class JalurSkema
{
    public const MAKS = 5;

    private const NAMA = '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/';

    private const INDEKS = '/^\d{1,2}$/';

    /**
     * @param  array<string, true>|null  $nama  nama properti skema tool (null = hanya cek struktur)
     */
    public static function sah(mixed $jalur, ?array $nama = null): bool
    {
        if (! is_string($jalur) || preg_match('#^(/[A-Za-z0-9_]{1,64}){1,6}$#', $jalur) !== 1) {
            return false;
        }

        foreach (explode('/', substr($jalur, 1)) as $i => $segmen) {
            $properti = preg_match(self::NAMA, $segmen) === 1 && ($nama === null || isset($nama[$segmen]));

            if ($i === 0 ? ! $properti : ! $properti && preg_match(self::INDEKS, $segmen) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<mixed>  $jalur
     * @param  array<string, true>|null  $nama
     * @return list<string>
     */
    public static function saring(array $jalur, ?array $nama = null): array
    {
        return array_slice(array_values(array_unique(array_filter($jalur, fn ($p) => self::sah($p, $nama)))), 0, self::MAKS);
    }

    /**
     * Semua nama properti pada skema (rekursif, termasuk item array dan additionalProperties).
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, true>
     */
    public static function namaProperti(array $schema, int $depth = 0): array
    {
        $nama = [];

        if ($depth > 6) {
            return $nama;
        }

        foreach ((array) ($schema['properties'] ?? []) as $prop => $child) {
            $nama[(string) $prop] = true;

            if (is_array($child)) {
                $nama += self::namaProperti($child, $depth + 1);
            }
        }

        foreach (['items', 'additionalProperties'] as $kunci) {
            if (is_array($schema[$kunci] ?? null)) {
                $nama += self::namaProperti($schema[$kunci], $depth + 1);
            }
        }

        return $nama;
    }
}
