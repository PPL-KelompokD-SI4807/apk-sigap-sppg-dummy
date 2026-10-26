<?php

namespace App\Services\Ai\Catalog;

use App\Enums\Role;
use App\Models\User;
use App\Services\Ai\Tools\ReadTool;
use App\Support\Rbac;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tool baca generik `cari_<sumber_daya>` dari satu ResourceSpec.
 *
 * - Daftar ringkas (maks 20 baris, default 10) dengan kata kunci `q`, filter deklaratif, dan rentang tanggal;
 *   argumen `id` mengembalikan detail satu data (kolom tambahan).
 * - Query Eloquent biasa (SppgScope aktif lewat ActingUser) + pembatasan SPPG eksplisit (SppgBound)
 *   + Policy `view` per baris (mis. relawan hanya melihat data miliknya). Tidak memakai withoutGlobalScopes.
 * - Kolom dipilih dari spesifikasi dan ditolak bila sensitif (RecordPresenter::assertSafe).
 */
class CatalogReadTool extends ReadTool
{
    public const MAX_ROWS = 20;

    public const DEFAULT_ROWS = 10;

    public function __construct(protected readonly ResourceSpec $spec)
    {
        RecordPresenter::assertSafe($spec->list);
        RecordPresenter::assertSafe($spec->detail);

        $this->name = $spec->toolName('R');
        $this->module = $spec->module;
        $this->model = $spec->model;
        $this->description = "Cari atau lihat data {$spec->label} milik SPPG pengguna (hasil ringkas, maks ".self::MAX_ROWS.' baris). '
            .($spec->search !== [] ? 'Kata kunci `q` mencocokkan '.implode(', ', array_map(fn (string $c) => str_replace('.', ' ', $c), $spec->search)).'. ' : '')
            .'Isi `id` untuk detail satu data. Kolom: '.implode(', ', array_map(fn ($a, $c) => is_string($a) ? $a : $c, array_keys($spec->list), $spec->list)).'.';
    }

    public function spec(): ResourceSpec
    {
        return $this->spec;
    }

    public function isAvailableFor(User $user): bool
    {
        if (! parent::isAvailableFor($user)) {
            return false;
        }

        return $this->spec->jenis === null || Rbac::canForJenis($user, $this->module, 'R', $this->spec->jenis);
    }

    public function parameters(): array
    {
        $properties = [];

        if ($this->spec->search !== []) {
            $properties['q'] = ['type' => 'string', 'maxLength' => 100, 'description' => 'Kata kunci pencarian.'];
        }

        $properties['id'] = ['type' => 'integer', 'minimum' => 1, 'description' => 'ID data untuk melihat detail satu data.'];

        foreach ($this->spec->filters as $name => $filter) {
            $properties[$name] = array_filter([
                'type' => $filter['type'] ?? 'string',
                'enum' => $filter['enum'] ?? null,
                'description' => $filter['description'] ?? null,
            ], fn ($v) => $v !== null);
        }

        if ($this->spec->date !== null) {
            $properties['dari'] = ['type' => 'string', 'format' => 'date', 'description' => 'Tanggal awal YYYY-MM-DD.'];
            $properties['sampai'] = ['type' => 'string', 'format' => 'date', 'description' => 'Tanggal akhir YYYY-MM-DD.'];
        }

        $properties['batas'] = ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_ROWS, 'description' => 'Jumlah hasil (default '.self::DEFAULT_ROWS.').'];

        return ['type' => 'object', 'properties' => $properties, 'required' => [], 'additionalProperties' => false];
    }

    protected function handle(User $user, array $arguments): array
    {
        $spec = $this->spec;
        $detail = isset($arguments['id']);
        $columns = $detail ? array_merge($spec->list, $spec->detail) : $spec->list;
        $limit = (int) ($arguments['batas'] ?? self::DEFAULT_ROWS);

        $query = SppgBound::apply($spec->model::query(), $user, $spec->scopeColumn);
        $relations = $this->relations($columns);

        if ($relations !== []) {
            $query->with($relations);
        }

        if ($detail) {
            $query->whereKey((int) $arguments['id']);
        } else {
            $this->filter($query, $arguments);

            foreach ($spec->order as [$column, $direction]) {
                $query->orderBy($query->getModel()->qualifyColumn($column), $direction);
            }
        }

        // Relawan dibatasi Policy per baris (data milik sendiri): pindai lebih banyak baris agar daftar tetap terisi.
        $scan = $detail ? 1 : ($user->role === Role::Relawan ? 100 : $limit + 1);
        $rows = $query->limit($scan)->get()->filter(fn (Model $m) => $this->allows($user, 'view', $m))->values();

        $shown = $detail ? $rows : $rows->take($limit);

        return [
            'jumlah' => $shown->count(),
            'ada_lagi' => ! $detail && $rows->count() > $limit,
            'hasil' => $shown->map(fn (Model $m) => RecordPresenter::present($m, $columns, $spec->rupiah, $detail ? RecordPresenter::MAX_TEXT_DETAIL : RecordPresenter::MAX_TEXT, $spec->initials))->all(),
            'catatan' => RecordPresenter::NOTE,
        ];
    }

    /**
     * @param  Builder<Model>  $query
     * @param  array<string, mixed>  $arguments
     */
    private function filter(Builder $query, array $arguments): void
    {
        $model = $query->getModel();
        $term = trim((string) ($arguments['q'] ?? ''));

        if ($term !== '' && $this->spec->search !== []) {
            $pattern = SppgBound::likePattern($term);
            $grammar = $query->getQuery()->getGrammar();

            $query->where(function (Builder $q) use ($model, $pattern, $grammar): void {
                foreach ($this->spec->search as $path) {
                    $segments = explode('.', $path);
                    $column = array_pop($segments);

                    if ($segments === []) {
                        $q->orWhereRaw($grammar->wrap($model->qualifyColumn($column))." LIKE ? ESCAPE '!'", [$pattern]);
                    } else {
                        $q->orWhereHas(implode('.', $segments), fn (Builder $r) => $r->whereRaw(
                            $r->getQuery()->getGrammar()->wrap($r->getModel()->qualifyColumn($column))." LIKE ? ESCAPE '!'",
                            [$pattern],
                        ));
                    }
                }
            });
        }

        foreach ($this->spec->filters as $name => $filter) {
            if (! array_key_exists($name, $arguments) || $arguments[$name] === null) {
                continue;
            }

            if (isset($filter['scope'])) {
                if ($arguments[$name] === true) {
                    $query->{$filter['scope']}();
                }

                continue;
            }

            $query->where($model->qualifyColumn($filter['column'] ?? $name), $arguments[$name]);
        }

        if ($this->spec->date !== null) {
            $column = $model->qualifyColumn($this->spec->date);

            if (isset($arguments['dari'])) {
                $query->whereDate($column, '>=', $arguments['dari']);
            }

            if (isset($arguments['sampai'])) {
                $query->whereDate($column, '<=', $arguments['sampai']);
            }
        }
    }

    /**
     * @param  array<int|string, string>  $columns
     * @return list<string>
     */
    private function relations(array $columns): array
    {
        $relations = [];

        foreach ($columns as $path) {
            if (str_contains($path, '.')) {
                $relations[] = substr($path, 0, (int) strrpos($path, '.'));
            }
        }

        return array_values(array_unique($relations));
    }
}
