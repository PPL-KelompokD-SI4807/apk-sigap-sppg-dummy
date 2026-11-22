<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\Scopes\SppgScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Cakupan SPPG daftar keuangan: admin boleh ?sppg_id=, peran lain dibatasi SppgScope. */
trait MenentukanSppgKeuangan
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function saringSppg(Builder $query, Request $request): Builder
    {
        if ($request->user()->isAdmin() && $request->filled('sppg_id') && ctype_digit((string) $request->query('sppg_id'))) {
            $query->where($query->getModel()->qualifyColumn('sppg_id'), (int) $request->query('sppg_id'));
        }

        return $query;
    }

    /** SPPG untuk ringkasan: admin wajib ?sppg_id=, lainnya SPPG sendiri. Null = tidak ada konteks. */
    protected function sppgRingkasan(Request $request): ?int
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            $id = $request->query('sppg_id');

            return is_string($id) && ctype_digit($id) ? (int) $id : null;
        }

        return SppgScope::currentUser()?->sppgAktifId();
    }
}
