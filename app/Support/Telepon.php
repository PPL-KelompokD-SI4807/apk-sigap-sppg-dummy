<?php

namespace App\Support;

/**
 * Normalisasi nomor telepon ke satu format (E.164, mis. "+6281234567890") agar 0812..., 62812...,
 * +62 812-... dan 812... dianggap nomor yang sama (identitas login R-03, indeks unik users.phone).
 * Nomor yang tidak dapat dikenali dikembalikan null (pemanggil memutuskan: tolak atau simpan apa adanya).
 */
final class Telepon
{
    public static function normalisasi(?string $nilai): ?string
    {
        if ($nilai === null) {
            return null;
        }

        $nilai = trim($nilai);
        $internasional = str_starts_with($nilai, '+');
        $digit = (string) preg_replace('/\D/', '', $nilai);

        if ($digit === '') {
            return null;
        }

        $hasil = match (true) {
            $internasional => $digit,
            str_starts_with($digit, '62') => $digit,
            str_starts_with($digit, '0') => '62'.substr($digit, 1),
            str_starts_with($digit, '8') => '62'.$digit,
            default => null,
        };

        if ($hasil === null || strlen($hasil) < 8 || strlen($hasil) > 15 || str_starts_with($hasil, '0')) {
            return null;
        }

        return '+'.$hasil;
    }
}
