<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StatusArmada;
use App\Http\Controllers\Api\V1\Concerns\FiltersMasterData;
use App\Http\Requests\Armada\StoreArmadaRequest;
use App\Http\Requests\Armada\UpdateArmadaRequest;
use App\Http\Resources\Armada\ArmadaResource;
use App\Models\Armada;
use App\Services\Ai\AiDraftConfirmer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Modul 12 Armada (Admin & Kepala SPPG CRUD; Akuntan & Relawan R). Pengemudi: PengemudiController.
 */
class ArmadaController extends ApiController
{
    use FiltersMasterData;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Armada::class);

        $query = Armada::query()->search($request->query('q'))->orderBy('plat_nomor')->orderBy('id');
        $this->filterSppg($query, $request);

        if (($status = $this->enumQuery($request, 'status', StatusArmada::class)) !== null) {
            $query->where('status', $status);
        }

        return ArmadaResource::collection($this->paginate($query, $request));
    }

    public function show(Armada $armada): ArmadaResource
    {
        $this->authorize('view', $armada);

        return new ArmadaResource($armada);
    }

    public function store(StoreArmadaRequest $request, AiDraftConfirmer $confirmer): JsonResponse
    {
        $armada = DB::transaction(function () use ($request, $confirmer): Armada {
            $armada = new Armada($request->safe()->except(['ai_draft_id', 'sppg_id']));

            if ($request->user()->isAdmin()) {
                $armada->sppg_id = (int) $request->validated('sppg_id');
            }

            $armada->save();
            $confirmer->confirm($request->validated('ai_draft_id'), $request->user(), 'armada');

            return $armada;
        });

        return (new ArmadaResource($armada->refresh()))->response()->setStatusCode(201);
    }

    public function update(UpdateArmadaRequest $request, Armada $armada, AiDraftConfirmer $confirmer): ArmadaResource
    {
        DB::transaction(function () use ($request, $armada, $confirmer): void {
            $armada->update($request->safe()->except('ai_draft_id'));
            $confirmer->confirm($request->validated('ai_draft_id'), $request->user(), 'armada', $armada->getKey());
        });

        return new ArmadaResource($armada->refresh());
    }

    public function destroy(Armada $armada): Response
    {
        $this->authorize('delete', $armada);

        if ($armada->status === StatusArmada::Bertugas) {
            throw ValidationException::withMessages(['armada' => ['Armada yang sedang bertugas tidak dapat dihapus.']]);
        }

        $armada->delete();

        return response()->noContent();
    }
}
