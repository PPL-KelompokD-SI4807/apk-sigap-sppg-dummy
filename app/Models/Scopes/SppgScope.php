<?php

namespace App\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Membatasi query ke SPPG pengguna yang sedang login.
 *
 * - Tidak ada pengguna login (console, queue, scheduler, rute publik): tidak dibatasi.
 *   Job yang bekerja atas nama pengguna wajib memfilter sendiri (scopeOfSppg).
 * - Admin: tidak dibatasi (lintas SPPG).
 * - Non-admin tanpa sppg_id: tidak melihat data apa pun (fail closed).
 *
 * Memakai Auth::hasUser() (bukan Auth::user()) agar tidak memicu resolusi guard
 * secara rekursif ketika Sanctum memuat tokenable User yang juga memakai scope ini.
 */
class SppgScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = self::currentUser();

        if ($user === null || $user->isAdmin()) {
            return;
        }

        $column = $model->qualifyColumn($model instanceof HasSppgColumn ? $model->sppgColumn() : 'sppg_id');

        if ($user->sppg_id === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($column, $user->sppg_id);
    }

    public static function currentUser(): ?User
    {
        if (! Auth::hasUser()) {
            return null;
        }

        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }
}
