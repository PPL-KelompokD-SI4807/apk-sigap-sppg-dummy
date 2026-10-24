<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StatusJadwalDistribusi;
use App\Http\Requests\Jadwal\StoreJadwalDistribusiRequest;
use App\Http\Requests\Jadwal\UpdateJadwalDistribusiRequest;
use App\Http\Resources\Jadwal\JadwalDistribusiResource;
use App\Models\JadwalDistribusi;
use App\Services\Ai\AiDraftConfirmer;
use App\Services\Jadwal\DistributionScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/** Modul 9 Data Jadwal Produksi dan Distribusi: bagian jadwal distribusi (FR-06/FR-08). */
class JadwalDistribusiController extends ApiController
{
    private const MODULE = 'jadwal';

    private const RELASI = ['titikLayanan:id,nama', 'armada:id,plat_nomor', 'pengemudi:id,nama', 'petugas:id,name', 'jadwalProduksi.pemeriksaanTerbaru'];

    public function __construct(
        private readonly DistributionScheduleService $distribusi,
        private readonly AiDraftConfirmer $draf,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', JadwalDistribusi::class);

        $query = JadwalDistribusi::query()->with(self::RELASI);
        $this->applySearch($query, $request, ['catatan']);

        if (in_array($request->query('status'), StatusJadwalDistribusi::values(), true)) {
            $query->where('status', $request->query('status'));
        }

        if (is_string($request->query('tanggal')) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->query('tanggal')) === 1) {
            $query->tanggal($request->query('tanggal'));
        }

        $this->applyDateRange($query, $request, 'tanggal_distribusi');

        foreach (['jadwal_produksi_id', 'titik_layanan_id', 'armada_id', 'pengemudi_id'] as $kolom) {
            if (is_numeric($request->query($kolom))) {
                $query->where($kolom, (int) $request->query($kolom));
            }
        }

        if ($request->user()->isAdmin() && is_numeric($request->query('sppg_id'))) {
            $query->where('sppg_id', (int) $request->query('sppg_id'));
        }

        return JadwalDistribusiResource::collection(
            $this->paginate($query->orderByDesc('tanggal_distribusi')->orderBy('jam_berangkat')->orderByDesc('id'), $request)
        );
    }

    public function store(StoreJadwalDistribusiRequest $request): JsonResponse
    {
        $this->authorize('create', JadwalDistribusi::class);
        $data = $request->validated();

        $jadwal = DB::transaction(function () use ($request, $data): JadwalDistribusi {
            $jadwal = $this->distribusi->buat($request->user(), $data);
            $this->draf->confirm($data['ai_draft_id'] ?? null, $request->user(), self::MODULE);

            return $jadwal;
        });

        return (new JadwalDistribusiResource($jadwal->load(self::RELASI)))->response()->setStatusCode(201);
    }

    public function show(JadwalDistribusi $jadwalDistribusi): JadwalDistribusiResource
    {
        $this->authorize('view', $jadwalDistribusi);

        return new JadwalDistribusiResource($jadwalDistribusi->load(self::RELASI));
    }

    public function update(UpdateJadwalDistribusiRequest $request, JadwalDistribusi $jadwalDistribusi): JadwalDistribusiResource
    {
        $this->authorize('update', $jadwalDistribusi);
        $data = $request->validated();

        $diubah = DB::transaction(function () use ($request, $jadwalDistribusi, $data): JadwalDistribusi {
            $diubah = $this->distribusi->perbarui($jadwalDistribusi, $data);
            $this->draf->confirm($data['ai_draft_id'] ?? null, $request->user(), self::MODULE, $diubah->id);

            return $diubah;
        });

        return new JadwalDistribusiResource($diubah->load(self::RELASI));
    }

    public function destroy(JadwalDistribusi $jadwalDistribusi): Response
    {
        $this->authorize('delete', $jadwalDistribusi);
        $this->distribusi->hapus($jadwalDistribusi);

        return response()->noContent();
    }
}
