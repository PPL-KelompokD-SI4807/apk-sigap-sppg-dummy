<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Modul Data SPPG (Tabel 5): Admin CRUD, Kepala SPPG RU, lainnya R. Tabel sppgs tidak memiliki
 * kolom sppg_id: pemilik SPPG adalah baris itu sendiri (id), sehingga non-admin hanya dapat
 * mengakses SPPG tempatnya bertugas.
 */
class SppgPolicy extends BaseSppgPolicy
{
    protected string $module = 'sppg';

    /**
     * Saldo awal (R-07 Q-2): hanya Akuntan pada SPPG-nya sendiri dan Admin. Kepala SPPG, peran lain, dan investor
     * ditolak walau punya U pada modul `sppg`. Pengguna nonaktif tidak punya hak.
     */
    public function ubahSaldoAwal(User $user, Model $model): bool
    {
        if (! $user->canAuthenticate()) {
            return false;
        }

        return $user->isAdmin() || ($user->hasRole(Role::Akuntan) && $this->sameSppg($user, $model));
    }

    protected function sppgIdOf(Model $model): ?int
    {
        $id = $model->getKey();

        return $id === null ? null : (int) $id;
    }
}
