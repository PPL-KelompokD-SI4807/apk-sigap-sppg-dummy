<?php

namespace App\Services\Ai\Catalog;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Pembatasan SPPG eksplisit untuk query tool (lapis kedua selain SppgScope): berlaku walau tool
 * dipanggil tanpa ActingUser. Admin lintas SPPG; non-admin tanpa SPPG tidak melihat apa pun.
 */
final class SppgBound
{
    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function apply(Builder $query, User $user, string $column = 'sppg_id'): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        $qualified = $query->getModel()->qualifyColumn($column);

        return $user->sppg_id === null
            ? $query->whereRaw('1 = 0')
            : $query->where($qualified, $user->sppg_id);
    }

    /** Pola LIKE aman: karakter khusus di-escape dengan '!'. */
    public static function likePattern(string $term): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($term)).'%';
    }

    /**
     * Cari satu record master by nama dalam SPPG pengguna: cocok persis (tanpa beda huruf besar) lebih
     * dulu, lalu mengandung kata kunci bila tepat satu. Null bila tidak ada / ambigu.
     *
     * @param  class-string<Model>  $modelClass
     * @return array{id: int, exact: bool}|null
     */
    public static function matchByName(string $modelClass, string $column, string $name, User $user): ?array
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        $base = fn (): Builder => self::apply($modelClass::query(), $user);
        $grammar = $base()->getQuery()->getGrammar();
        $wrapped = $grammar->wrap((new $modelClass)->qualifyColumn($column));

        $exact = $base()->whereRaw("LOWER({$wrapped}) = ?", [mb_strtolower($name)])->limit(2)->pluck('id');

        if ($exact->count() === 1) {
            return ['id' => (int) $exact->first(), 'exact' => true];
        }

        if ($exact->count() > 1) {
            return null;
        }

        $like = $base()->whereRaw("LOWER({$wrapped}) LIKE ? ESCAPE '!'", [mb_strtolower(self::likePattern($name))])->limit(2)->pluck('id');

        return $like->count() === 1 ? ['id' => (int) $like->first(), 'exact' => false] : null;
    }
}
