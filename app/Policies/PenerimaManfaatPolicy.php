<?php

namespace App\Policies;

/**
 * Modul Penerima Manfaat: matriks CRUD Tabel 5 (config/rbac.php, slug 'penerima-manfaat') + isolasi SPPG dari BaseSppgPolicy.
 */
class PenerimaManfaatPolicy extends BaseSppgPolicy
{
    protected string $module = 'penerima-manfaat';
}
