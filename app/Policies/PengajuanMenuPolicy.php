<?php

namespace App\Policies;

use App\Models\PengajuanMenu;
use App\Models\User;
use App\Support\Rbac;
use Illuminate\Database\Eloquent\Model;

/**
 * Persetujuan menu (R-06b-1): baca mengikuti matriks (modul 'persetujuan-menu') + SPPG aktif.
 * Memutuskan HANYA investor pemilik SPPG aktif (Rbac::canDecide). Pengajuan imutabel: tidak ada
 * jalur create/update/delete lewat Policy (pengajuan dibuat MenuController::ajukan via MenuPolicy).
 */
class PengajuanMenuPolicy extends BaseSppgPolicy
{
    protected string $module = 'persetujuan-menu';

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Model $model): bool
    {
        return false;
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function restore(User $user, Model $model): bool
    {
        return false;
    }

    public function putuskan(User $user, PengajuanMenu $pengajuan): bool
    {
        return Rbac::canDecide($user, $this->module) && $this->sameSppg($user, $pengajuan);
    }
}
