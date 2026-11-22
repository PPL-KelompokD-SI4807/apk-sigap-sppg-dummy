<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Modul 1 Data Pengguna: admin CRUD lintas SPPG, kepala_sppg membaca pengguna SPPG-nya,
 * peran lain hanya profil sendiri (R*). Investor (sppg_id null) juga hanya profil sendiri.
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

    /** Kelola kepemilikan SPPG investor (R-06a): hanya Admin (hak U pengguna lintas SPPG). */
    public function kelolaKepemilikan(User $user, User $target): bool
    {
        return $user->isAdmin() && $this->update($user, $target);
    }

    /**
     * Profil sendiri selalu "satu SPPG" dengan pemiliknya: investor tidak punya users.sppg_id
     * tetapi tetap boleh membaca profilnya (R*). Record pengguna lain tetap lewat aturan dasar.
     */
    protected function sameSppg(User $user, Model $model): bool
    {
        return ($model instanceof User && $model->is($user)) || parent::sameSppg($user, $model);
    }
}
