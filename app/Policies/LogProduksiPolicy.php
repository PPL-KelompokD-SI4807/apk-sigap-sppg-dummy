<?php

namespace App\Policies;

/**
 * Modul 10 Log Produksi Harian: relawan unit dapur CRU (update hanya log buatannya sendiri pada hari
 * yang sama; pembuat = dicatat_oleh); kepala_sppg RUD (tanpa C); peran lain baca.
 */
class LogProduksiPolicy extends BaseSppgPolicy
{
    protected string $module = 'log-produksi';

    protected ?string $ownerColumn = 'dicatat_oleh';
}
