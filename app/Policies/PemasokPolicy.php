<?php

namespace App\Policies;

/**
 * Modul Pemasok: matriks CRUD Tabel 5 (config/rbac.php, slug 'pemasok') + isolasi SPPG dari BaseSppgPolicy.
 */
class PemasokPolicy extends BaseSppgPolicy
{
    protected string $module = 'pemasok';
}
