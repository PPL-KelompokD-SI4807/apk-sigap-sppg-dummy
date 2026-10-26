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
 * - Investor (R-06a): dibatasi ke SATU SPPG aktif tervalidasi (User::sppgAktifId(), header
 *   X-SPPG-Id dicek ke pivot investor_sppg); tanpa SPPG aktif -> fail closed. Pada tabel users
 *   investor juga selalu melihat baris dirinya sendiri (sppg_id null) agar profil R* dapat dimuat;
 *   daftar pengguna tetap ditolak UserPolicy (R* = hanya profil sendiri).
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

        $sppgId = $user->sppgAktifId();

        if ($user->isInvestor() && $model instanceof User) {
            $builder->where(function (Builder $q) use ($model, $column, $sppgId, $user): void {
                $q->where($model->qualifyColumn($model->getKeyName()), $user->getKey());

                if ($sppgId !== null) {
                    $q->orWhere($column, $sppgId);
                }
            });

            return;
        }

        if ($sppgId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($column, $sppgId);
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
