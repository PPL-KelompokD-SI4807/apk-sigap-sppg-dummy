<?php

namespace App\Http\Requests\LaporanBgn;

/**
 * POST permintaan PDF laporan BGN (R07-3). Parameter periode dan aturan akses identik dengan GET laporan
 * (LaporanBgnPolicy::lihat, dicek sebelum validasi): investor ditolak 403 untuk 30b dan 30d-C, lintas SPPG 403.
 */
class MintaPdfLaporanBgnRequest extends LaporanBgnRequest
{
    protected function kemampuan(): string
    {
        return 'lihat';
    }
}
