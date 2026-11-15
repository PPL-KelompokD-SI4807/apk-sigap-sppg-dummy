<?php

namespace App\Support;

use App\Services\Auth\GalatAuth;
use Illuminate\Database\Eloquent\Model;

/**
 * Pembandingan `versi` kunci optimistik, sama dengan pola Pengeluaran/PembelianBahan: 409 `versi_tidak_cocok`
 * beserta `versi_server`. Panggil HANYA pada baris yang sudah dikunci (lockForUpdate) dalam transaksi.
 * `$versi` null (klien lama yang belum mengirim versi) = tanpa pemeriksaan.
 */
final class KunciVersi
{
    public const ALASAN = 'versi_tidak_cocok';

    public static function periksa(Model $terkunci, ?int $versi, string $nama): void
    {
        if ($versi !== null && (int) $terkunci->getAttribute('versi') !== $versi) {
            throw GalatAuth::respons(409, self::ALASAN, $nama.' sudah berubah. Muat ulang lalu ubah kembali.', tambahan: ['versi_server' => (int) $terkunci->getAttribute('versi')]);
        }
    }
}
