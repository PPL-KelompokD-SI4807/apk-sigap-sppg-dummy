<?php

namespace App\Policies;

/**
 * Modul 16 Rekap Harian: admin/ahli_gizi/akuntan R; kepala_sppg CRU; staf_gudang dan relawan tanpa akses.
 * Snapshot disusun sistem/Kepala SPPG sehingga tidak ada kolom pemilik (tanpa aturan kepemilikan).
 */
class RekapHarianPolicy extends BaseSppgPolicy
{
    protected string $module = 'rekap-harian';

    protected ?string $ownerColumn = null;
}
