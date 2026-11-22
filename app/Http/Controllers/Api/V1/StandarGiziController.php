<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\MenuGizi\StoreStandarGiziRequest;
use App\Http\Resources\MenuGizi\StandarGiziResource;
use App\Models\StandarGizi;
use App\Services\Ai\AiDraftConfirmer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Standar gizi per kelompok sasaran (bagian modul 8 Menu dan Gizi). */
class StandarGiziController extends ApiController
{
    private const MODULE = 'menu-gizi';

    private const RESOURCE = 'standar_gizi';

    public function __construct(private readonly AiDraftConfirmer $draf) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', StandarGizi::class);

        return StandarGiziResource::collection(
            $this->paginate(StandarGizi::query()->orderBy('sppg_id')->orderBy('kelompok_sasaran'), $request)
        );
    }

    public function store(StoreStandarGiziRequest $request): JsonResponse
    {
        $this->authorize('create', StandarGizi::class);
        $data = $request->validated();
        $sppgId = $request->user()->sppg_id;

        $standar = DB::transaction(function () use ($request, $data, $sppgId): StandarGizi {
            $ada = StandarGizi::withTrashed()
                ->where('sppg_id', $sppgId)
                ->where('kelompok_sasaran', $data['kelompok_sasaran'])
                ->lockForUpdate()
                ->first();

            if ($ada !== null && ! $ada->trashed()) {
                throw ValidationException::withMessages([
                    'kelompok_sasaran' => ['Standar gizi untuk kelompok sasaran ini sudah ada. Ubah standar yang ada.'],
                ]);
            }

            if ($ada !== null) {
                $ada->restore();
                $ada->fill($data)->save();
                $standar = $ada;
            } else {
                $standar = new StandarGizi;
                $standar->fill($data)->save();
            }

            $this->draf->confirm($data['ai_draft_id'] ?? null, $request->user(), self::MODULE, resource: self::RESOURCE);

            return $standar;
        });

        return (new StandarGiziResource($standar))->response()->setStatusCode(201);
    }

    public function show(StandarGizi $standarGizi): StandarGiziResource
    {
        $this->authorize('view', $standarGizi);

        return new StandarGiziResource($standarGizi);
    }

    public function update(StoreStandarGiziRequest $request, StandarGizi $standarGizi): StandarGiziResource
    {
        $this->authorize('update', $standarGizi);
        $data = $request->validated();

        if (isset($data['kelompok_sasaran']) && $data['kelompok_sasaran'] !== $standarGizi->kelompok_sasaran->value) {
            $bentrok = StandarGizi::withTrashed()
                ->where('sppg_id', $standarGizi->sppg_id)
                ->where('kelompok_sasaran', $data['kelompok_sasaran'])
                ->exists();

            if ($bentrok) {
                throw ValidationException::withMessages([
                    'kelompok_sasaran' => ['Standar gizi untuk kelompok sasaran ini sudah ada.'],
                ]);
            }
        }

        DB::transaction(function () use ($request, $standarGizi, $data): void {
            $standarGizi->fill($data)->save();
            $this->draf->confirm($data['ai_draft_id'] ?? null, $request->user(), self::MODULE, $standarGizi->getKey(), resource: self::RESOURCE);
        });

        return new StandarGiziResource($standarGizi);
    }

    public function destroy(Request $request, StandarGizi $standarGizi): Response
    {
        $this->authorize('delete', $standarGizi);
        $this->hapusDenganDraf($request, self::MODULE, (int) $standarGizi->getKey(), fn () => $standarGizi->delete(), self::RESOURCE);

        return response()->noContent();
    }
}
