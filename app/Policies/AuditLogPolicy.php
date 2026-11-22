<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

/**
 * Log audit hanya boleh dibaca Admin aktif (lintas SPPG). Semua peran lain, termasuk Kepala SPPG dan Investor,
 * mendapat 403. Log bersifat append-only: tidak ada kemampuan tulis (create/update/delete tidak didefinisikan,
 * sehingga Gate menolaknya). Sesi login penuh dipastikan middleware rute.
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() && $user->canAuthenticate();
    }

    public function view(User $user, AuditLog $log): bool
    {
        return $this->viewAny($user);
    }
}
