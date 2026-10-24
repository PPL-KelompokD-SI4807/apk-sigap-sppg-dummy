<?php

namespace App\Policies;

/**
 * Modul Titik Layanan: matriks CRUD Tabel 5 (config/rbac.php, slug 'titik-layanan') + isolasi SPPG dari BaseSppgPolicy.
 */
class TitikLayananPolicy extends BaseSppgPolicy
{
    protected string $module = 'titik-layanan';
}
