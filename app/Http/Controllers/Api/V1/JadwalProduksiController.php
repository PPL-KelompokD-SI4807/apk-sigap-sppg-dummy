<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StatusJadwalDistribusi;
use App\Enums\StatusJadwalProduksi;
use App\Enums\StatusPersetujuanMenu;
use App\Http\Requests\Jadwal\StoreJadwalProduksiRequest;
use App\Http\Requests\Jadwal\UpdateJadwalProduksiRequest;
use App\Http\Resources\Jadwal\JadwalProduksiResource;
use App\Models\JadwalProduksi;
use App\Services\Ai\AiDraftConfirmer;
use App\Services\Jadwal\ProductionPlanningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
            ->with(['menu:id,nama_menu,kelompok_sasaran,status_persetujuan', 'pemeriksaanTerbaru'])
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

        if ($request->boolean('menu_belum_disetujui')) {
            $query->whereDoesntHave('menu', fn ($m) => $m->where('status_persetujuan', StatusPersetujuanMenu::Disetujui->value));
        }

        if ($request->user()->isAdmin() && is_numeric($request->query('sppg_id'))) {
            $query->where('sppg_id', (int) $request->query('sppg_id'));
        }

        return JadwalProduksiResource::collection(
            $this->paginate($query->orderByDesc('tanggal_produksi')->orderBy('jam_mulai_masak')->orderByDesc('id'), $request)
        );
    }

    /**
     * Pilihan jadwal produksi untuk dropdown (form distribusi, keluhan): HANYA id, tanggal, nama menu, status.
     * Gerbang `viewRingkas` (hak R modul jadwal, bukan R*, tanpa batasan sub-jenis unit relawan); dibatasi SPPG
     * oleh SppgScope. Tanpa target porsi, catatan, anggaran, atau data pengguna. Default hari ini (WIB);
     * `from`/`to` (selisih to - from maks. 62 hari = 63 hari kalender inklusif) untuk rentang, mis. batch keluhan 14 hari terakhir.
     */
    public function ringkas(Request $request): JsonResponse
    {
        $this->authorize('viewRingkas', JadwalProduksi::class);

        $request->validate([
            'tanggal' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'sppg_id' => ['nullable', 'integer'],
        ]);

        $zona = config('operasional.timezone');
        $query = JadwalProduksi::query()
            ->select(['id', 'menu_id', 'tanggal_produksi', 'status'])
            ->with('menu:id,nama_menu');

        if ($request->filled('from') || $request->filled('to')) {
            $dari = Carbon::parse((string) ($request->query('from') ?? $request->query('to')), $zona);
            $sampai = Carbon::parse((string) ($request->query('to') ?? $request->query('from')), $zona);

            if ($dari->diffInDays($sampai) > 62) {
                throw ValidationException::withMessages(['to' => ['Selisih from dan to maksimal 62 hari.']]);
            }

            $query->whereDate('tanggal_produksi', '>=', $dari->toDateString())->whereDate('tanggal_produksi', '<=', $sampai->toDateString());
        } else {
            $query->tanggal($request->filled('tanggal') ? (string) $request->query('tanggal') : Carbon::now($zona)->toDateString());
        }

        if ($request->user()->isAdmin() && $request->filled('sppg_id')) {
            $query->where('sppg_id', (int) $request->query('sppg_id'));
        }

        $data = $query->orderByDesc('tanggal_produksi')->orderBy('jam_mulai_masak')->orderBy('id')->limit(200)->get()
            ->map(fn (JadwalProduksi $j) => [
                'id' => $j->id,
                'tanggal_produksi' => $j->tanggal_produksi?->toDateString(),
                'nama_menu' => $j->menu?->nama_menu,
                'status' => $j->status->value,
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

    public function destroy(Request $request, JadwalProduksi $jadwal): Response
    {
        $this->authorize('delete', $jadwal);
        $this->hapusDenganDraf($request, 'jadwal', (int) $jadwal->getKey(), fn () => $this->perencanaan->hapus($jadwal));

        return response()->noContent();
    }

    public function kebutuhanBahan(JadwalProduksi $jadwal): JsonResponse
    {
        $this->authorize('view', $jadwal);

        return response()->json(['data' => $this->perencanaan->kebutuhanBahan($jadwal)]);
    }

    private function muat(JadwalProduksi $jadwal): JadwalProduksi
    {
        return $jadwal->load(['menu:id,nama_menu,kelompok_sasaran,status_persetujuan', 'pemeriksaanTerbaru'])
            ->loadSum(['jadwalDistribusis as porsi_terjadwal_distribusi' => fn ($q) => $q->where('status', '!=', StatusJadwalDistribusi::Dibatalkan->value)], 'jumlah_porsi');
    }
}
