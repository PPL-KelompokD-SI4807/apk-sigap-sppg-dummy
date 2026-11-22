<?php

namespace App\Policies;

use App\Models\User;

/**
 * Dashboard laporan per peran (R-07). Bukan sumber daya per-record: gerbang `dashboard.lihat` hanya
 * memastikan pengguna aktif dengan peran dikenal dan (selain Admin/Investor) punya SPPG. Isi widget disaring
 * lagi per modul oleh DashboardService (Rbac::can R), dan himpunan SPPG ditentukan service dari sumber otoritatif.
 * Sesi login penuh dipastikan middleware rute (EnsureTokenAbility auth:penuh).
 */
class DashboardPolicy
{
    public function lihat(User $user): bool
    {
        if (! $user->canAuthenticate()) {
            return false;
        }

        return $user->isAdmin() || $user->isInvestor() || $user->sppg_id !== null;
    }
}
