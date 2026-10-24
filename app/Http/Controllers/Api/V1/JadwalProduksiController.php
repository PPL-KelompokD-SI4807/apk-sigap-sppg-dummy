<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StatusJadwalDistribusi;
use App\Enums\StatusJadwalProduksi;
use App\Http\Requests\Jadwal\StoreJadwalProduksiRequest;
use App\Http\Requests\Jadwal\UpdateJadwalProduksiRequest;
use App\Http\Resources\Jadwal\JadwalProduksiResource;
use App\Models\JadwalProduksi;
use App\Services\Ai\AiDraftConfirmer;
use App\Services\Jadwal\ProductionPlanningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/** Modul 9 Data Jadwal Produksi dan Distribusi: bagian jadwal produksi (FR-06). */
class JadwalProduksiController extends ApiController
{
    private const MODULE = 'jadwal';

    public function __construct(
        private readonly ProductionPlanningService $perencanaan,
        private readonly AiDraftConfirmer $draf,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', JadwalProduksi::class);

        $query = JadwalProduksi::query()
            ->with(['menu:id,nama_menu,kelompok_sasaran', 'pemeriksaanTerbaru'])
            ->withSum(['jadwalDistribusis as porsi_terjadwal_distribusi' => fn ($q) => $q->where('status', '!=', StatusJadwalDistribusi::Dibatalkan->value)], 'jumlah_porsi');

        $term = mb_substr(trim((string) $request->query('q', '')), 0, 100);

        if ($term !== '') {
            $query->where(fn ($q) => $q->search($term)->orWhereHas('menu', fn ($m) => $m->search($term)));
        }

        if (in_array($request->query('status'), StatusJadwalProduksi::values(), true)) {
            $query->where('status', $request->query('status'));
        }

        if (is_string($request->query('tanggal')) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->query('tanggal')) === 1) {
            $query->tanggal($request->query('tanggal'));
        }

        $this->applyDateRange($query, $request, 'tanggal_produksi');

        if (is_numeric($request->query('menu_id'))) {
            $query->where('menu_id', (int) $request->query('menu_id'));
        }

        if ($request->user()->isAdmin() && is_numeric($request->query('sppg_id'))) {
            $query->where('sppg_id', (int) $request->query('sppg_id'));
        }

        return JadwalProduksiResource::collection(
            $this->paginate($query->orderByDesc('tanggal_produksi')->orderBy('jam_mulai_masak')->orderByDesc('id'), $request)
        );
    }

    /** Ringkasan jadwal produksi per tanggal untuk form distribusi (hak R jadwal, tanpa batasan unit). */
    public function ringkas(Request $request): JsonResponse
    {
        $this->authorize('viewRingkas', JadwalProduksi::class);

        $request->validate([
            'tanggal' => ['nullable', 'date_format:Y-m-d'],
            'sppg_id' => ['nullable', 'integer'],
        ]);

        $tanggal = $request->filled('tanggal')
            ? (string) $request->query('tanggal')
            : Carbon::now(config('operasional.timezone'))->toDateString();

        $query = JadwalProduksi::query()
            ->with('menu:id,nama_menu')
            ->tanggal($tanggal);

        if ($request->user()->isAdmin() && $request->filled('sppg_id')) {
            $query->where('sppg_id', (int) $request->query('sppg_id'));
        }

        $data = $query->orderBy('jam_mulai_masak')->orderBy('id')->limit(200)->get()
            ->map(fn (JadwalProduksi $j) => [
                'id' => $j->id,
                'tanggal_produksi' => $j->tanggal_produksi?->toDateString(),
                'nama_menu' => $j->menu?->nama_menu,
                'target_porsi' => $j->target_porsi,
                'status' => $j->status->value,
                'status_label' => $j->status->label(),
            ])->values();

        return response()->json(['data' => $data]);
    }

    public function store(StoreJadwalProduksiRequest $request): JsonResponse
    {
        $this->authorize('create', JadwalProduksi::class);
        $data = $request->validated();

        $jadwal = DB::transaction(function () use ($request, $data): JadwalProduksi {
            $jadwal = $this->perencanaan->buat($request->user(), $data);
            $this->draf->confirm($data['ai_draft_id'] ?? null, $request->user(), self::MODULE);

            return $jadwal;
        });

        $kekurangan = $this->perencanaan->umumkanKekurangan($jadwal);

        return (new JadwalProduksiResource($this->muat($jadwal)))
            ->additional(['kekurangan_bahan' => $kekurangan])
            ->response()->setStatusCode(201);
    }

    public function show(JadwalProduksi $jadwal): JadwalProduksiResource
    {
        $this->authorize('view', $jadwal);

        return new JadwalProduksiResource($this->muat($jadwal));
    }

    public function update(UpdateJadwalProduksiRequest $request, JadwalProduksi $jadwal): JadwalProduksiResource
    {
        $this->authorize('update', $jadwal);
        $data = $request->validated();

        $diubah = DB::transaction(function () use ($request, $jadwal, $data): JadwalProduksi {
            $diubah = $this->perencanaan->perbarui($jadwal, $data);
            $this->draf->confirm($data['ai_draft_id'] ?? null, $request->user(), self::MODULE, $diubah->id);

            return $diubah;
        });

        if (isset($data['menu_id']) || isset($data['target_porsi']) || isset($data['tanggal_produksi'])) {
            $this->perencanaan->umumkanKekurangan($diubah);
        }

        return new JadwalProduksiResource($this->muat($diubah));
    }

    public function destroy(JadwalProduksi $jadwal): Response
    {
        $this->authorize('delete', $jadwal);
        $this->perencanaan->hapus($jadwal);

        return response()->noContent();
    }

    public function kebutuhanBahan(JadwalProduksi $jadwal): JsonResponse
    {
        $this->authorize('view', $jadwal);

        return response()->json(['data' => $this->perencanaan->kebutuhanBahan($jadwal)]);
    }

    private function muat(JadwalProduksi $jadwal): JadwalProduksi
    {
        return $jadwal->load(['menu:id,nama_menu,kelompok_sasaran', 'pemeriksaanTerbaru'])
            ->loadSum(['jadwalDistribusis as porsi_terjadwal_distribusi' => fn ($q) => $q->where('status', '!=', StatusJadwalDistribusi::Dibatalkan->value)], 'jumlah_porsi');
    }
}
