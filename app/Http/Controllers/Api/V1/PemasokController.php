<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\JenisPemasok;
use App\Http\Controllers\Api\V1\Concerns\FiltersMasterData;
use App\Http\Requests\Pemasok\StorePemasokRequest;
use App\Http\Requests\Pemasok\UpdatePemasokRequest;
use App\Http\Resources\Pemasok\PemasokResource;
use App\Models\Pemasok;
use App\Services\Ai\AiDraftConfirmer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Modul 7 Pemasok (Admin CRUD, Staf Gudang CRU, lainnya R). Penghapusan selalu lunak:
 * tidak ada jalur hapus permanen sehingga riwayat bahan baku/mutasi/pengeluaran tetap utuh.
 */
class PemasokController extends ApiController
{
    use FiltersMasterData;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Pemasok::class);

        $query = Pemasok::query()->search($request->query('q'))->orderBy('nama')->orderBy('id');
        $this->filterSppg($query, $request);

        if (($jenis = $this->enumQuery($request, 'jenis', JenisPemasok::class)) !== null) {
            $query->where('jenis', $jenis);
        }

        if (($active = $this->boolQuery($request, 'is_active')) !== null) {
            $query->where('is_active', $active);
        }

        return PemasokResource::collection($this->paginate($query, $request));
    }

    public function show(Pemasok $pemasok): PemasokResource
    {
        $this->authorize('view', $pemasok);

        return new PemasokResource($pemasok->loadCount('bahanBakus'));
    }

    public function store(StorePemasokRequest $request, AiDraftConfirmer $confirmer): JsonResponse
    {
        $pemasok = DB::transaction(function () use ($request, $confirmer): Pemasok {
            $pemasok = new Pemasok($request->safe()->except(['ai_draft_id', 'sppg_id']));

            if ($request->user()->isAdmin()) {
                $pemasok->sppg_id = (int) $request->validated('sppg_id');
            }

            $pemasok->save();
            $confirmer->confirm($request->validated('ai_draft_id'), $request->user(), 'pemasok');

            return $pemasok;
        });

        return (new PemasokResource($pemasok->refresh()))->response()->setStatusCode(201);
    }

    public function update(UpdatePemasokRequest $request, Pemasok $pemasok, AiDraftConfirmer $confirmer): PemasokResource
    {
        DB::transaction(function () use ($request, $pemasok, $confirmer): void {
            $pemasok->update($request->safe()->except('ai_draft_id'));
            $confirmer->confirm($request->validated('ai_draft_id'), $request->user(), 'pemasok', $pemasok->getKey());
        });

        return new PemasokResource($pemasok->refresh()->loadCount('bahanBakus'));
    }

    public function destroy(Pemasok $pemasok): Response
    {
        $this->authorize('delete', $pemasok);

        // Hapus lunak saja (SoftDeletes); forceDelete ditolak policy dan tidak ada endpointnya.
        $pemasok->delete();

        return response()->noContent();
    }
}
