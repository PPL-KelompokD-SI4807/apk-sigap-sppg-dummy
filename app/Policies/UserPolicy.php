<?php

namespace App\Policies;

use App\Models\User;

/**
 * Modul 1 Data Pengguna: admin CRUD lintas SPPG, kepala_sppg membaca pengguna SPPG-nya,
 * peran lain hanya profil sendiri (R*).
 */
class UserPolicy extends BaseSppgPolicy
{
    protected string $module = 'pengguna';

    /**
     * Lihat/cabut sesi login pengguna lain (R-03): butuh hak ubah pengguna dalam scope (Admin).
     * Sesi milik sendiri dikelola lewat /auth/sesi, bukan rute ini.
     */
    public function kelolaSesi(User $user, User $target): bool
    {
        return $this->update($user, $target);
    }
}
