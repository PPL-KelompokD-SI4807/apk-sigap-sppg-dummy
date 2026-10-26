<?php

namespace App\Policies;

/**
 * Modul 1 Data Pengguna: admin CRUD lintas SPPG, kepala_sppg membaca pengguna SPPG-nya,
 * peran lain hanya profil sendiri (R*).
 */
class UserPolicy extends BaseSppgPolicy
{
    protected string $module = 'pengguna';
}
