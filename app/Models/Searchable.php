<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * Pencarian teks sederhana (LIKE) pada kolom yang dideklarasikan di $searchable.
 * Karakter khusus LIKE di-escape dengan '!' agar portabel antara MySQL dan SQLite.
 *
 * Pemakaian: Model::query()->search($request->query('q'))
 */
trait Searchable
{
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        $kolom = property_exists($this, 'searchable') ? $this->searchable : [];

        if ($term === '' || $kolom === []) {
            return $query;
        }

        $pola = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
        $grammar = $query->getQuery()->getGrammar();

        return $query->where(function (Builder $q) use ($kolom, $pola, $grammar) {
            foreach ($kolom as $nama) {
                $q->orWhereRaw($grammar->wrap($this->qualifyColumn($nama))." LIKE ? ESCAPE '!'", [$pola]);
            }
        });
    }
}
