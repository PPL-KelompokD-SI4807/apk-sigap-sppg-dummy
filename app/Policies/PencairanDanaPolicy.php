<?php

namespace App\Policies;

/**
 * Modul 14 Data Keuangan — pencairan dana (Tabel 5): akuntan CRUD, admin & kepala_sppg R,
 * peran lain tanpa akses. Isolasi SPPG dari BaseSppgPolicy.
 */
class PencairanDanaPolicy extends BaseSppgPolicy
{
    protected string $module = 'keuangan';

    protected ?string $ownerColumn = 'dicatat_oleh';
}
