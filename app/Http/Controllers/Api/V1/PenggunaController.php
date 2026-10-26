<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Http\Controllers\Api\V1\Concerns\FiltersMasterData;
use App\Http\Requests\Pengguna\StorePenggunaRequest;
use App\Http\Requests\Pengguna\UpdatePenggunaRequest;
use App\Http\Resources\Pengguna\PenggunaResource;
use App\Models\User;
use App\Services\Ai\AiDraftConfirmer;
use App\Services\Users\UserAccountService;
use App\Support\Rbac;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Modul 1 Data Pengguna. Admin CRUD; Kepala SPPG R (SPPG-nya); peran lain R* (profil sendiri).
 */
class PenggunaController extends ApiController
{
    use FiltersMasterData;

    public function __construct(private readonly UserAccountService $accounts) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $actor = $request->user();
        $query = User::query()->with('sppg')->orderBy('name')->orderBy('id');

        if (Rbac::isSelfOnly($actor, 'pengguna', 'R')) {
            // R*: daftar hanya berisi profil sendiri.
            $query->whereKey($actor->getKey());
        } else {
            $this->authorize('viewAny', User::class);
            $this->applyFilters($query, $request);
        }

        return PenggunaResource::collection($this->paginate($query, $request));
    }

    public function show(User $user): PenggunaResource
    {
        $this->authorize('view', $user);

        return new PenggunaResource($user->load('sppg'));
    }

    public function store(StorePenggunaRequest $request, AiDraftConfirmer $confirmer): JsonResponse
    {
        $user = DB::transaction(function () use ($request, $confirmer): User {
            $user = $this->accounts->create($request->safe()->except('ai_draft_id'));
            $confirmer->confirm($request->validated('ai_draft_id'), $request->user(), 'pengguna');

            return $user;
        });

        return (new PenggunaResource($user->load('sppg')))->response()->setStatusCode(201);
    }

    public function update(UpdatePenggunaRequest $request, User $user, AiDraftConfirmer $confirmer): PenggunaResource
    {
        DB::transaction(function () use ($request, $user, $confirmer): void {
            $this->accounts->update($request->user(), $user, $request->safe()->except('ai_draft_id'));
            $confirmer->confirm($request->validated('ai_draft_id'), $request->user(), 'pengguna', $user->getKey());
        });

        return new PenggunaResource($user->refresh()->load('sppg'));
    }

    public function destroy(Request $request, User $user): Response
    {
        $this->authorize('delete', $user);

        $this->accounts->delete($request->user(), $user);

        return response()->noContent();
    }

    /** @param Builder<User> $query */
    private function applyFilters(Builder $query, Request $request): void
    {
        $this->applySearch($query, $request, ['name', 'username', 'phone', 'telegram_username']);
        $this->filterSppg($query, $request);

        if (($role = $this->enumQuery($request, 'role', Role::class)) !== null) {
            $query->where('role', $role);
        }

        if (($unit = $this->enumQuery($request, 'unit', RelawanUnit::class)) !== null) {
            $query->where('unit', $unit);
        }

        if (($active = $this->boolQuery($request, 'is_active')) !== null) {
            $query->where('is_active', $active);
        }

        match ($request->query('status')) {
            'aktif' => $query->where('is_active', true)->whereNotNull('telegram_id'),
            'nonaktif' => $query->where('is_active', false),
            'menunggu_telegram' => $query->where('is_active', true)->whereNull('telegram_id'),
            default => null,
        };
    }
}
