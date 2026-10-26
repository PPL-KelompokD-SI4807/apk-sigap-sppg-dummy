<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Riwayat persetujuan pengeluaran (R-06b-2): hanya dibaca lewat resource pengeluaran (modul keuangan).
 * Imutabel: tidak ada jalur create/update/delete lewat Policy.
 */
class PersetujuanPengeluaranPolicy extends BaseSppgPolicy
{
    protected string $module = 'keuangan';

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
}
