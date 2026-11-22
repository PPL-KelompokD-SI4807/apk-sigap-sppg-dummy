<?php

namespace App\Http\Requests\LaporanBgn;

/**
 * POST nomor LPA (R07-2). Aturan periode sama dengan GET laporan; yang berbeda hanya kemampuan Policy
 * (`terbitkanNomor`: admin, kepala_sppg, akuntan; investor 403), tetap dicek SEBELUM validasi.
 */
class TerbitkanNomorLaporanBgnRequest extends LaporanBgnRequest
{
    protected function kemampuan(): string
    {
        return 'terbitkanNomor';
    }
}
