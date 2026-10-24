<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Filter daftar umum modul data master. Nilai dari query string selalu divalidasi
 * (enum/boolean/integer); nilai tak valid diabaikan, bukan dipakai mentah.
 */
trait FiltersMasterData
{
    protected function boolQuery(Request $request, string $key): ?bool
    {
        if (! $request->query->has($key)) {
            return null;
        }

        return filter_var($request->query($key), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /**
     * @param  class-string<\BackedEnum>  $enum
     */
    protected function enumQuery(Request $request, string $key, string $enum): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $enum::tryFrom($value) !== null ? $value : null;
    }

    /**
     * Admin dapat menyaring per SPPG; peran lain sudah dibatasi SppgScope.
     *
     * @param  Builder<Model>  $query
     */
    protected function filterSppg(Builder $query, Request $request): void
    {
        $user = $request->user();
        $sppgId = filter_var($request->query('sppg_id'), FILTER_VALIDATE_INT);

        if ($user instanceof User && $user->isAdmin() && $sppgId !== false) {
            $query->where($query->getModel()->qualifyColumn('sppg_id'), $sppgId);
        }
    }
}
