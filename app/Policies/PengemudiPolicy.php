<?php

namespace App\Policies;

/**
 * Modul Pengemudi: matriks CRUD Tabel 5 (config/rbac.php, slug 'armada') + isolasi SPPG dari BaseSppgPolicy.
 */
class PengemudiPolicy extends BaseSppgPolicy
{
    protected string $module = 'armada';
}
