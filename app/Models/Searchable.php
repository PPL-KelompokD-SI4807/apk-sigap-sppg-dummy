<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * Pencarian teks sederhana (LIKE) pada kolom yang dideklarasikan di $searchable.
 * Karakter khusus LIKE di-escape dengan '!' agar portabel antara MySQL dan SQLite.
 *
 * Pemakaian: Model::query()->search($request->query('q'))
 * Parameter $batasKolom (opsional) mempersempit kolom yang dicari (mis. agar kolom sensitif tidak
 * dapat ditebak lewat pencarian oleh peran yang tidak boleh melihatnya).
 */
trait Searchable
{
    public function scopeSearch(Builder $query, ?string $term, ?array $batasKolom = null): Builder
    {
        $term = trim((string) $term);
        $kolom = property_exists($this, 'searchable') ? $this->searchable : [];
        if ($batasKolom !== null) {
            $kolom = array_values(array_intersect($kolom, $batasKolom));
        }

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
