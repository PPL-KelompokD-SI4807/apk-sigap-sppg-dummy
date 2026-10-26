<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StatusOperasional;
use App\Http\Controllers\Api\V1\Concerns\FiltersMasterData;
use App\Http\Requests\Sppg\StoreSppgRequest;
use App\Http\Requests\Sppg\UpdateSppgRequest;
use App\Http\Resources\Sppg\SppgResource;
use App\Models\Sppg;
use App\Services\Ai\AiDraftConfirmer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Modul 3 Data SPPG. Admin CRUD; Kepala SPPG RU (SPPG sendiri); peran lain R (SPPG sendiri).
 */
class SppgController extends ApiController
{
    use FiltersMasterData;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Sppg::class);
        $user = $request->user();

        $query = Sppg::query()->search($request->query('q'))->orderBy('nama')->orderBy('id');

        if (! $user->isAdmin()) {
            // Investor: hanya SPPG aktif tervalidasi (bukan seluruh SPPG miliknya); lihat /sppg-saya.
            $sppgId = $user->sppgAktifId();
            $sppgId === null ? $query->whereRaw('1 = 0') : $query->whereKey($sppgId);
        }

        if (($status = $this->enumQuery($request, 'status_operasional', StatusOperasional::class)) !== null) {
            $query->where('status_operasional', $status);
        }

        return SppgResource::collection($this->paginate($query, $request));
    }

    public function show(Sppg $sppg): SppgResource
    {
        $this->authorize('view', $sppg);

        return new SppgResource($sppg);
    }

    public function store(StoreSppgRequest $request, AiDraftConfirmer $confirmer): JsonResponse
    {
        $sppg = DB::transaction(function () use ($request, $confirmer): Sppg {
            $sppg = Sppg::query()->create($request->safe()->except('ai_draft_id'));
            $confirmer->confirm($request->validated('ai_draft_id'), $request->user(), 'sppg');

            return $sppg;
        });

        return (new SppgResource($sppg->refresh()))->response()->setStatusCode(201);
    }

    public function update(UpdateSppgRequest $request, Sppg $sppg, AiDraftConfirmer $confirmer): SppgResource
    {
        DB::transaction(function () use ($request, $sppg, $confirmer): void {
            $sppg->update($request->safe()->except('ai_draft_id'));
            $confirmer->confirm($request->validated('ai_draft_id'), $request->user(), 'sppg', $sppg->getKey());
        });

        return new SppgResource($sppg->refresh());
    }

    public function destroy(Sppg $sppg): Response
    {
        $this->authorize('delete', $sppg);

        if ($sppg->users()->exists() || $sppg->titikLayanans()->exists()) {
            throw ValidationException::withMessages([
                'sppg' => ['SPPG masih memiliki pengguna atau titik layanan aktif. Pindahkan atau hapus datanya terlebih dahulu.'],
            ]);
        }

        $sppg->delete();

        return response()->noContent();
    }
}
