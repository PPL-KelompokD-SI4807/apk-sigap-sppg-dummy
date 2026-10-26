<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Rbac;

/**
 * Modul Penerima Manfaat: matriks CRUD Tabel 5 (config/rbac.php, slug 'penerima-manfaat') + isolasi SPPG dari BaseSppgPolicy.
 */
class PenerimaManfaatPolicy extends BaseSppgPolicy
{
    protected string $module = 'penerima-manfaat';

    /**
     * Agregat penerima (hitungan per kategori, tanpa data pribadi): peran ber-hak R modul ini atau
     * tercantum di config('rbac.agregat.penerima-manfaat') (investor, R-06a).
     */
    public function viewAgregat(User $user): bool
    {
        return Rbac::canAggregate($user, $this->module);
    }
}
