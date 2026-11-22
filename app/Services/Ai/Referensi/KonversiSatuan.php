<?php

namespace App\Services\Ai\Referensi;

/**
 * Konversi satuan PASTI untuk takaran dari chat: hanya dalam satu dimensi dengan faktor pangkat 10
 * (massa: mg/g/kg; volume: ml/l). Massa <-> volume TIDAK pernah dikonversi (butuh massa jenis = tebakan).
 *
 * Aritmetika desimal berbasis STRING (geser titik desimal), bukan float, agar 0,01 kg menjadi tepat 10 g.
 */
final class KonversiSatuan
{
    public const MASSA = 'massa';

    public const VOLUME = 'volume';

    /** Alias satuan (huruf kecil) => [dimensi, pangkat 10 ke satuan dasar (g / ml), label dasar]. */
    private const SATUAN = [
        'mg' => [self::MASSA, -3], 'miligram' => [self::MASSA, -3],
        'g' => [self::MASSA, 0], 'gr' => [self::MASSA, 0], 'gram' => [self::MASSA, 0], 'grm' => [self::MASSA, 0],
        'kg' => [self::MASSA, 3], 'kilo' => [self::MASSA, 3], 'kilogram' => [self::MASSA, 3],
        'ml' => [self::VOLUME, 0], 'mililiter' => [self::VOLUME, 0], 'cc' => [self::VOLUME, 0],
        'l' => [self::VOLUME, 3], 'lt' => [self::VOLUME, 3], 'ltr' => [self::VOLUME, 3], 'liter' => [self::VOLUME, 3], 'litre' => [self::VOLUME, 3],
    ];

    /** Maksimal digit desimal masukan (lebih dari ini ditolak, bukan dibulatkan diam-diam). */
    public const MAKS_DESIMAL_MASUKAN = 6;

    /** @return array{0: string, 1: int}|null [dimensi, pangkat] */
    public static function kenali(?string $satuan): ?array
    {
        $kunci = mb_strtolower(trim((string) $satuan));
        $kunci = rtrim($kunci, '.');

        return self::SATUAN[$kunci] ?? null;
    }

    public static function dimensi(?string $satuan): ?string
    {
        return self::kenali($satuan)[0] ?? null;
    }

    public static function labelDasar(string $dimensi): string
    {
        return $dimensi === self::VOLUME ? 'ml' : 'gram';
    }

    /**
     * Angka desimal kanonik (string, tanpa tanda, tanpa nol berlebih) dari int/float/string numerik positif;
     * null bila bukan angka > 0 atau terlalu presisi.
     */
    public static function angka(mixed $nilai): ?string
    {
        if (is_int($nilai)) {
            $teks = (string) $nilai;
        } elseif (is_float($nilai) && is_finite($nilai)) {
            $teks = rtrim(rtrim(sprintf('%.'.self::MAKS_DESIMAL_MASUKAN.'F', $nilai), '0'), '.');
            // Float yang tidak dapat diwakili dalam MAKS_DESIMAL_MASUKAN digit (mis. 0.1234567) ditolak.
            if (abs((float) $teks - $nilai) > 1e-9 * max(1.0, abs($nilai))) {
                return null;
            }
        } elseif (is_string($nilai) && preg_match('/^\s*\d{1,9}(?:[.,]\d{1,'.self::MAKS_DESIMAL_MASUKAN.'})?\s*$/', $nilai) === 1) {
            $teks = str_replace(',', '.', trim($nilai));
        } else {
            return null;
        }

        if (preg_match('/^\d{1,12}(?:\.\d{1,'.self::MAKS_DESIMAL_MASUKAN.'})?$/', $teks) !== 1) {
            return null;
        }

        $teks = self::geser($teks, 0);

        return preg_match('/[1-9]/', $teks) === 1 ? $teks : null;
    }

    /** Geser titik desimal sebanyak $pangkat (positif = kali 10^n), eksak. Masukan: desimal non-negatif kanonik. */
    public static function geser(string $angka, int $pangkat): string
    {
        [$bulat, $pecahan] = array_pad(explode('.', $angka, 2), 2, '');
        $digit = $bulat.$pecahan;
        $posisi = strlen($bulat) + $pangkat;

        if ($posisi <= 0) {
            $digit = str_repeat('0', 1 - $posisi).$digit;
            $posisi = 1;
        }

        if ($posisi > strlen($digit)) {
            $digit .= str_repeat('0', $posisi - strlen($digit));
        }

        $b = ltrim(substr($digit, 0, $posisi), '0');
        $p = rtrim(substr($digit, $posisi), '0');

        return ($b === '' ? '0' : $b).($p === '' ? '' : '.'.$p);
    }

    public static function jumlahDesimal(string $angka): int
    {
        $titik = strpos($angka, '.');

        return $titik === false ? 0 : strlen($angka) - $titik - 1;
    }

    /** Nilai JSON dari desimal kanonik ber-desimal <= 2: int bila bulat, selain itu float (representasi tepat). */
    public static function keJson(string $angka): int|float
    {
        return str_contains($angka, '.') ? (float) $angka : (int) $angka;
    }
}
