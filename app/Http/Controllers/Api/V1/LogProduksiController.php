<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\LogProduksi\StoreLogProduksiRequest;
use App\Http\Requests\LogProduksi\UpdateLogProduksiRequest;
use App\Http\Resources\LogProduksi\LogProduksiResource;
use App\Models\LogProduksi;
use App\Services\Ai\AiDraftConfirmer;
use App\Services\Produksi\LogProduksiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/** Modul 10 Data Log Produksi Harian (FR-06). */
class LogProduksiController extends ApiController
{
    private const MODULE = 'log-produksi';

    private const RELASI = ['jadwalProduksi:id,menu_id,tanggal_produksi,target_porsi,status', 'jadwalProduksi.menu:id,nama_menu', 'pencatat:id,name'];

    public function __construct(
        private readonly LogProduksiService $service,
        private readonly AiDraftConfirmer $draf,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', LogProduksi::class);

        $query = LogProduksi::query()->with(self::RELASI);
        $this->applySearch($query, $request, ['kendala', 'kode_batch']);

        if (is_numeric($request->query('jadwal_produksi_id'))) {
            $query->where('jadwal_produksi_id', (int) $request->query('jadwal_produksi_id'));
        }

        $tanggal = $request->query('tanggal');
        $dari = $request->query('from');
        $sampai = $request->query('to');
        $valid = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1;

        if ($valid($tanggal) || $valid($dari) || $valid($sampai)) {
            $query->whereHas('jadwalProduksi', function ($q) use ($tanggal, $dari, $sampai, $valid): void {
                $valid($tanggal) && $q->whereDate('tanggal_produksi', $tanggal);
                $valid($dari) && $q->whereDate('tanggal_produksi', '>=', $dari);
                $valid($sampai) && $q->whereDate('tanggal_produksi', '<=', $sampai);
            });
        }

        if ($request->user()->isAdmin() && is_numeric($request->query('sppg_id'))) {
            $query->where('sppg_id', (int) $request->query('sppg_id'));
        }

        return LogProduksiResource::collection($this->paginate($query->orderByDesc('id'), $request));
    }

    public function store(StoreLogProduksiRequest $request): JsonResponse
    {
        $this->authorize('create', LogProduksi::class);
        $data = $request->validated();

        $log = DB::transaction(function () use ($request, $data): LogProduksi {
            $log = $this->service->catat($request->user(), $data);
            $this->draf->confirm($data['ai_draft_id'] ?? null, $request->user(), self::MODULE);

            return $log;
        });

        return (new LogProduksiResource($log->load(self::RELASI)))->response()->setStatusCode(201);
    }

    public function show(LogProduksi $logProduksi): LogProduksiResource
    {
        $this->authorize('view', $logProduksi);

        return new LogProduksiResource($logProduksi->load(self::RELASI));
    }

    public function update(UpdateLogProduksiRequest $request, LogProduksi $logProduksi): LogProduksiResource
    {
        $this->authorize('update', $logProduksi);
        $data = $request->validated();

        $log = DB::transaction(function () use ($request, $logProduksi, $data): LogProduksi {
            $log = $this->service->perbarui($logProduksi, $data);
            $this->draf->confirm($data['ai_draft_id'] ?? null, $request->user(), self::MODULE, $log->id);

            return $log;
        });

        return new LogProduksiResource($log->load(self::RELASI));
    }

    public function destroy(LogProduksi $logProduksi): Response
    {
        $this->authorize('delete', $logProduksi);
        $logProduksi->delete();

        return response()->noContent();
    }
}
