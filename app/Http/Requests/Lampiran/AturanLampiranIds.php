<?php

namespace App\Http\Requests\Lampiran;

use App\Services\Storage\PengikatLampiran;

/**
 * Aturan bentuk `lampiran_ids` untuk store/update modul yang menerima lampiran (keluhan, mutu,
 * log-produksi). Keabsahan tiap id (pemilik, SPPG, belum terikat, kategori) diperiksa
 * PengikatLampiran di dalam transaksi modul.
 */
final class AturanLampiranIds
{
    /** @return array<string, list<mixed>> */
    public static function untuk(string $modul): array
    {
        $maks = (int) config("unggahan.modul.{$modul}.maks", PengikatLampiran::MAKS_DEFAULT);

        return [
            'lampiran_ids' => ['nullable', 'array', 'max:'.$maks],
            'lampiran_ids.*' => ['integer', 'min:1', 'distinct'],
        ];
    }
}
