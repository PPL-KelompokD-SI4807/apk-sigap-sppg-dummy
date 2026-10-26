<?php

namespace App\Policies;

/**
 * Modul Armada: matriks CRUD Tabel 5 (config/rbac.php, slug 'armada') + isolasi SPPG dari BaseSppgPolicy.
 */
class ArmadaPolicy extends BaseSppgPolicy
{
    protected string $module = 'armada';
}
