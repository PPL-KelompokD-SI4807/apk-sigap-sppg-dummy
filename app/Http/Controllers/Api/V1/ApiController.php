<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

/**
 * Basis seluruh controller REST /api/v1.
 *
 * - Otorisasi: $this->authorize('viewAny', Model::class) / $this->authorize('update', $model).
 * - Paginasi: ?page=1&per_page=15 (maks 100) -> Resource::collection($paginator) menghasilkan
 *   {data, links, meta} (lihat components/Pagination di OpenAPI).
 * - Pencarian: ?q=kata (LIKE aman, wildcard di-escape) pada kolom yang diizinkan controller.
 */
abstract class ApiController extends Controller
{
    use AuthorizesRequests;

    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    protected function perPage(Request $request): int
    {
        $perPage = filter_var($request->query('per_page'), FILTER_VALIDATE_INT);

        if ($perPage === false || $perPage < 1) {
            return self::DEFAULT_PER_PAGE;
        }

        return min($perPage, self::MAX_PER_PAGE);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return LengthAwarePaginator<int, TModel>
     */
    protected function paginate(Builder $query, Request $request): LengthAwarePaginator
    {
        return $query->paginate($this->perPage($request))->withQueryString();
    }

    /**
     * Terapkan ?q= pada kolom yang diizinkan (OR). Nama kolom HARUS dari kode, bukan input.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $columns
     * @return Builder<TModel>
     */
    protected function applySearch(Builder $query, Request $request, array $columns, string $param = 'q'): Builder
    {
        $term = trim((string) $request->query($param, ''));

        if ($term === '' || $columns === []) {
            return $query;
        }

        $term = mb_substr($term, 0, 100);
        $like = '%'.addcslashes($term, '\\%_').'%';

        return $query->where(function (Builder $q) use ($columns, $like) {
            foreach ($columns as $column) {
                $q->orWhere($q->getModel()->qualifyColumn($column), 'like', $like);
            }
        });
    }

    /**
     * Terapkan ?from=YYYY-MM-DD&to=YYYY-MM-DD pada kolom tanggal.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function applyDateRange(Builder $query, Request $request, string $column): Builder
    {
        foreach (['from' => '>=', 'to' => '<='] as $param => $operator) {
            $value = $request->query($param);

            if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                $query->whereDate($query->getModel()->qualifyColumn($column), $operator, $value);
            }
        }

        return $query;
    }
}
