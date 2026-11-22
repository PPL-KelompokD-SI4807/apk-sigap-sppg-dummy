<?php

namespace App\Policies;

use App\Models\PermintaanResetPassword;
use App\Models\User;
use App\Support\Rbac;

/**
 * Permintaan reset password (R-03): hanya pengguna dengan hak ubah Data Pengguna (Admin) yang
 * melihat dan memutus, dan hanya untuk pengguna dalam scope-nya (UserPolicy::update). Permintaan
 * milik sendiri tidak pernah bisa diputus sendiri.
 */
class PermintaanResetPasswordPolicy
{
    public function viewAny(User $user): bool
    {
        return Rbac::can($user, 'pengguna', 'U') && ! Rbac::isSelfOnly($user, 'pengguna', 'U');
    }

    public function putuskan(User $user, PermintaanResetPassword $permintaan): bool
    {
        $pemohon = User::query()->withoutGlobalScopes()->find($permintaan->user_id);

        return $pemohon !== null
            && ! $pemohon->is($user)
            && $user->can('update', $pemohon);
    }
}
