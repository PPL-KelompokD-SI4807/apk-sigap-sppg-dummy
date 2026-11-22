<?php

namespace App\Services\DataUji;

use App\Models\Sppg;

/**
 * Penanda data uji staging (perintah sigap:isi-data-uji).
 *
 * Mekanisme tunggal: seluruh data uji adalah turunan SPPG berkode KODE (rantai `sppg_id`). Tidak ada kolom
 * penanda baru dan tidak ada migrasi. Satu-satunya tabel anak tanpa `sppg_id` adalah `menu_bahan`; baris itu
 * ditentukan lewat `menu_id` milik SPPG uji. Nama setiap entitas uji berakhiran SUFIKS sebagai penanda kedua
 * yang kasatmata, dan pemeriksaan kode + sufiks nama SPPG dipakai sebagai pengaman sebelum penghapusan.
 */
final class PenandaDataUji
{
    public const KODE_SPPG = 'SPPG-UJI-01';

    public const SUFIKS = '(Uji)';

    /** SPPG uji bila ada (termasuk yang terhapus lunak), tanpa global scope. */
    public static function sppg(): ?Sppg
    {
        return Sppg::query()->withoutGlobalScopes()->withTrashed()->where('kode', self::KODE_SPPG)->first();
    }

    /** Hanya SPPG berkode uji DAN bernama berakhiran sufiks yang boleh diperlakukan sebagai data uji. */
    public static function adalahDataUji(Sppg $sppg): bool
    {
        return $sppg->kode === self::KODE_SPPG && str_ends_with(rtrim((string) $sppg->nama), self::SUFIKS);
    }
}
