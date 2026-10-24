<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\FiltersMasterData;
use App\Http\Requests\Armada\StorePengemudiRequest;
use App\Http\Requests\Armada\UpdatePengemudiRequest;
use App\Http\Resources\Armada\PengemudiResource;
use App\Models\Pengemudi;
use App\Services\Ai\AiDraftConfirmer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Pengemudi (bagian modul 12 Armada dan Pengemudi; hak mengikuti slug 'armada').
 * Tabel pengemudis tidak terkait langsung ke armadas (relasi lewat jadwal distribusi),
 * sehingga endpointnya terpisah: /pengemudi.
 */
class PengemudiController extends ApiController
{
    use FiltersMasterData;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Pengemudi::class);

        $query = Pengemudi::query()->search($request->query('q'))->orderBy('nama')->orderBy('id');
        $this->filterSppg($query, $request);

        if (($active = $this->boolQuery($request, 'is_active')) !== null) {
            $query->where('is_active', $active);
        }

        return PengemudiResource::collection($this->paginate($query, $request));
    }

    public function show(Pengemudi $pengemudi): PengemudiResource
    {
        $this->authorize('view', $pengemudi);

        return new PengemudiResource($pengemudi);
    }

    public function store(StorePengemudiRequest $request, AiDraftConfirmer $confirmer): JsonResponse
    {
        $pengemudi = DB::transaction(function () use ($request, $confirmer): Pengemudi {
            $pengemudi = new Pengemudi($request->safe()->except(['ai_draft_id', 'sppg_id']));

            if ($request->user()->isAdmin()) {
                $pengemudi->sppg_id = (int) $request->validated('sppg_id');
            }

            $pengemudi->save();
            $confirmer->confirm($request->validated('ai_draft_id'), $request->user(), 'armada');

            return $pengemudi;
        });

        return (new PengemudiResource($pengemudi->refresh()))->response()->setStatusCode(201);
    }

    public function update(UpdatePengemudiRequest $request, Pengemudi $pengemudi, AiDraftConfirmer $confirmer): PengemudiResource
    {
        DB::transaction(function () use ($request, $pengemudi, $confirmer): void {
            $pengemudi->update($request->safe()->except('ai_draft_id'));
            $confirmer->confirm($request->validated('ai_draft_id'), $request->user(), 'armada', $pengemudi->getKey());
        });

        return new PengemudiResource($pengemudi->refresh());
    }

    public function destroy(Pengemudi $pengemudi): Response
    {
        $this->authorize('delete', $pengemudi);

        $pengemudi->delete();

        return response()->noContent();
    }
}
