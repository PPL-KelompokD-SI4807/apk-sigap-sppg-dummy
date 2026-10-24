<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\JenisMutasi;
use App\Enums\KategoriBahan;
use App\Http\Requests\BahanBaku\StoreBahanBakuRequest;
use App\Http\Requests\BahanBaku\StoreMutasiStokRequest;
use App\Http\Requests\BahanBaku\UpdateBahanBakuRequest;
use App\Http\Resources\BahanBaku\BahanBakuResource;
use App\Http\Resources\BahanBaku\MutasiStokResource;
use App\Models\BahanBaku;
use App\Models\MutasiStok;
use App\Services\Ai\AiDraftConfirmer;
use App\Services\Stock\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Modul 6 Data Bahan Baku + mutasi stok (FR-04). */
class BahanBakuController extends ApiController
{
    private const MODULE = 'bahan-baku';

    public function __construct(
        private readonly StockService $stok,
        private readonly AiDraftConfirmer $draf,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', BahanBaku::class);

        $query = BahanBaku::query()->with('pemasok:id,nama');
        $this->applySearch($query, $request, ['nama', 'kode']);

        if (in_array($request->query('kategori'), KategoriBahan::values(), true)) {
            $query->where('kategori', $request->query('kategori'));
        }

        if (is_numeric($request->query('pemasok_id'))) {
            $query->where('pemasok_id', (int) $request->query('pemasok_id'));
        }

        if ($request->query('stok_menipis') === '1') {
            $query->stokMenipis();
        }

        if ($request->user()->isAdmin() && is_numeric($request->query('sppg_id'))) {
            $query->where('sppg_id', (int) $request->query('sppg_id'));
        }

        return BahanBakuResource::collection($this->paginate($query->orderBy('nama')->orderBy('id'), $request));
    }

    public function stokMenipis(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', BahanBaku::class);

        $query = BahanBaku::query()->with('pemasok:id,nama')->stokMenipis()
            ->orderByRaw('(stok - stok_minimum) asc')->orderBy('nama');

        return BahanBakuResource::collection($this->paginate($query, $request));
    }

    public function kedaluwarsaDekat(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', BahanBaku::class);

        $hari = filter_var($request->query('hari'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 365]]);
        $hari = $hari === false ? (int) config('gudang.kedaluwarsa_hari', 3) : $hari;

        $query = BahanBaku::query()->with('pemasok:id,nama')->akanKedaluwarsa($hari)
            ->where('stok', '>', 0)
            ->orderBy('kedaluwarsa_terdekat')->orderBy('nama');

        return BahanBakuResource::collection($this->paginate($query, $request));
    }

    public function store(StoreBahanBakuRequest $request): JsonResponse
    {
        $this->authorize('create', BahanBaku::class);
        $data = $request->validated();

        $bahan = DB::transaction(function () use ($request, $data): BahanBaku {
            $bahan = new BahanBaku;
            $bahan->fill($data);

            if ($request->user()->isAdmin()) {
                $bahan->sppg_id = $data['sppg_id'];
            }

            $bahan->save();
            $this->draf->confirm($data['ai_draft_id'] ?? null, $request->user(), self::MODULE);

            return $bahan;
        });

        return (new BahanBakuResource($bahan->load('pemasok:id,nama')))->response()->setStatusCode(201);
    }

    public function show(BahanBaku $bahanBaku): BahanBakuResource
    {
        $this->authorize('view', $bahanBaku);

        return new BahanBakuResource($bahanBaku->load('pemasok:id,nama'));
    }

    public function update(UpdateBahanBakuRequest $request, BahanBaku $bahanBaku): BahanBakuResource
    {
        $this->authorize('update', $bahanBaku);
        $data = $request->validated();

        DB::transaction(function () use ($request, $bahanBaku, $data): void {
            $bahanBaku->fill($data)->save();
            $this->draf->confirm($data['ai_draft_id'] ?? null, $request->user(), self::MODULE, $bahanBaku->id);
        });

        return new BahanBakuResource($bahanBaku->load('pemasok:id,nama'));
    }

    public function destroy(BahanBaku $bahanBaku): Response
    {
        $this->authorize('delete', $bahanBaku);

        $dipakai = DB::table('menu_bahan')
            ->join('menus', 'menus.id', '=', 'menu_bahan.menu_id')
            ->where('menu_bahan.bahan_baku_id', $bahanBaku->id)
            ->whereNull('menus.deleted_at')
            ->exists();

        if ($dipakai) {
            throw ValidationException::withMessages([
                'bahan_baku' => ['Bahan baku masih dipakai pada komposisi menu. Lepaskan dari menu terlebih dahulu.'],
            ]);
        }

        $bahanBaku->delete();

        return response()->noContent();
    }

    public function mutasi(Request $request, BahanBaku $bahanBaku): AnonymousResourceCollection
    {
        $this->authorize('view', $bahanBaku);

        $query = MutasiStok::query()->where('bahan_baku_id', $bahanBaku->id)->with(['pemasok:id,nama', 'pencatat:id,name']);

        if (in_array($request->query('jenis'), JenisMutasi::values(), true)) {
            $query->where('jenis', $request->query('jenis'));
        }

        $this->applyDateRange($query, $request, 'tanggal_mutasi');

        return MutasiStokResource::collection(
            $this->paginate($query->orderByDesc('tanggal_mutasi')->orderByDesc('id'), $request)
        );
    }

    public function catatMutasi(StoreMutasiStokRequest $request, BahanBaku $bahanBaku): JsonResponse
    {
        $this->authorize('update', $bahanBaku);
        $data = $request->validated();

        $mutasi = DB::transaction(function () use ($request, $bahanBaku, $data): MutasiStok {
            $mutasi = $this->stok->catat(
                $bahanBaku,
                JenisMutasi::from($data['jenis']),
                $data['jumlah'],
                $request->user(),
                $data,
            );
            $this->draf->confirm($data['ai_draft_id'] ?? null, $request->user(), self::MODULE, $bahanBaku->id, resource: 'mutasi_stok');

            return $mutasi;
        });

        $mutasi->load(['pemasok:id,nama', 'pencatat:id,name']);

        return (new MutasiStokResource($mutasi))->additional([
            'bahan_baku' => (new BahanBakuResource($mutasi->bahanBaku->load('pemasok:id,nama')))->resolve($request),
        ])->response()->setStatusCode(201);
    }

    public function batch(BahanBaku $bahanBaku): JsonResponse
    {
        $this->authorize('view', $bahanBaku);

        return response()->json([
            'data' => $this->stok->batch($bahanBaku)->map(fn (array $b) => [
                ...$b,
                'jumlah_awal' => (float) $b['jumlah_awal'],
                'sisa' => (float) $b['sisa'],
            ])->all(),
            'stok_tanpa_batch' => (float) $this->stok->stokTanpaBatch($bahanBaku),
        ]);
    }
}
