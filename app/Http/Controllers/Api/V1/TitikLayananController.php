<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\JenisTitikLayanan;
use App\Enums\TipeUndangan;
use App\Http\Controllers\Api\V1\Concerns\FiltersMasterData;
use App\Http\Requests\TitikLayanan\StoreTitikLayananRequest;
use App\Http\Requests\TitikLayanan\UpdateTitikLayananRequest;
use App\Http\Resources\Pengguna\UndanganDibuatResource;
use App\Http\Resources\TitikLayanan\TitikLayananResource;
use App\Models\TitikLayanan;
use App\Services\Ai\AiDraftConfirmer;
use App\Services\Users\InvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Modul 4 Titik Layanan (Admin & Kepala SPPG CRUD; lainnya R) + undangan PIC.
 */
class TitikLayananController extends ApiController
{
    use FiltersMasterData;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TitikLayanan::class);

        $query = TitikLayanan::query()->search($request->query('q'))->orderBy('nama')->orderBy('id');
        $this->filterSppg($query, $request);

        if (($jenis = $this->enumQuery($request, 'jenis', JenisTitikLayanan::class)) !== null) {
            $query->where('jenis', $jenis);
        }

        if (($active = $this->boolQuery($request, 'is_active')) !== null) {
            $query->where('is_active', $active);
        }

        return TitikLayananResource::collection($this->paginate($query, $request));
    }

    public function show(TitikLayanan $titikLayanan): TitikLayananResource
    {
        $this->authorize('view', $titikLayanan);

        return new TitikLayananResource($titikLayanan);
    }

    public function store(StoreTitikLayananRequest $request, AiDraftConfirmer $confirmer): JsonResponse
    {
        $titik = DB::transaction(function () use ($request, $confirmer): TitikLayanan {
            $titik = new TitikLayanan($request->safe()->except(['ai_draft_id', 'sppg_id']));

            if ($request->user()->isAdmin()) {
                $titik->sppg_id = (int) $request->validated('sppg_id');
            }

            // Non-admin: BelongsToSppg memaksa sppg_id = SPPG pengguna.
            $titik->save();
            $confirmer->confirm($request->validated('ai_draft_id'), $request->user(), 'titik-layanan');

            return $titik;
        });

        return (new TitikLayananResource($titik->refresh()))->response()->setStatusCode(201);
    }

    public function update(UpdateTitikLayananRequest $request, TitikLayanan $titikLayanan, AiDraftConfirmer $confirmer): TitikLayananResource
    {
        DB::transaction(function () use ($request, $titikLayanan, $confirmer): void {
            $titikLayanan->update($request->safe()->except('ai_draft_id'));
            $confirmer->confirm($request->validated('ai_draft_id'), $request->user(), 'titik-layanan', $titikLayanan->getKey());
        });

        return new TitikLayananResource($titikLayanan->refresh());
    }

    public function destroy(TitikLayanan $titikLayanan): Response
    {
        $this->authorize('delete', $titikLayanan);

        $titikLayanan->delete();

        return response()->noContent();
    }

    /** POST /titik-layanan/{id}/undangan-pic */
    public function undanganPic(Request $request, TitikLayanan $titikLayanan, InvitationService $invitations): JsonResponse
    {
        $this->authorize('update', $titikLayanan);

        $issued = $invitations->issue($request->user(), TipeUndangan::PicTitikLayanan, [
            'titik_layanan_id' => $titikLayanan->getKey(),
        ]);

        return UndanganDibuatResource::created($issued, $request);
    }
}
