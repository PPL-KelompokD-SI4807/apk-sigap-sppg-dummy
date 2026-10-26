<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Enums\StatusPerjalanan;
use App\Http\Requests\Perjalanan\StoreNotifikasiPerjalananRequest;
use App\Http\Requests\Perjalanan\UbahStatusPerjalananRequest;
use App\Http\Resources\Concerns\FormatsWib;
use App\Http\Resources\NotifikasiPerjalananResource;
use App\Models\JadwalDistribusi;
use App\Models\NotifikasiPerjalanan;
use App\Services\Ai\AiDraftConfirmer;
use App\Services\Perjalanan\TripNotificationService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class NotifikasiPerjalananController extends ApiController
{
    use FormatsWib;

    public function __construct(private readonly TripNotificationService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', NotifikasiPerjalanan::class);

        $data = $request->validate([
            'tanggal' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', Rule::enum(StatusPerjalanan::class)],
            'titik_layanan_id' => ['nullable', 'integer'],
            'jadwal_distribusi_id' => ['nullable', 'integer'],
        ]);

        $zona = $this->service->zona();
        $hari = Carbon::createFromFormat('Y-m-d', $data['tanggal'] ?? $this->service->sekarang()->toDateString(), $zona);
        $awal = $hari->copy()->startOfDay();
        $akhir = $hari->copy()->endOfDay();

        $query = $this->cakupan($request)->with(['titikLayanan', 'pemicu'])
            // Baris tanpa waktu_berangkat (data lama) memakai waktu pencatatan.
            ->where(function (Builder $q) use ($awal, $akhir) {
                $q->whereBetween('waktu_berangkat', [$awal, $akhir])
                    ->orWhere(fn (Builder $w) => $w->whereNull('waktu_berangkat')->whereBetween('created_at', [$awal, $akhir]));
            })
            ->orderByDesc('estimasi_tiba')->orderByDesc('id');

        $this->filter($query, $data);

        return NotifikasiPerjalananResource::collection($this->paginate($query, $request));
    }

    public function terakhir(Request $request): JsonResponse
    {
        $this->authorize('viewAny', NotifikasiPerjalanan::class);

        $data = $request->validate([
            'titik_layanan_id' => ['nullable', 'integer'],
            'jadwal_distribusi_id' => ['nullable', 'integer'],
        ]);

        $query = $this->cakupan($request)->with(['titikLayanan', 'pemicu'])
            ->orderByRaw('coalesce(waktu_berangkat, created_at) desc')->orderByDesc('id');
        $this->filter($query, $data);

        $terakhir = $query->first();

        return response()->json(['data' => $terakhir === null ? null : (new NotifikasiPerjalananResource($terakhir))->resolve()]);
    }

    public function pratinjau(StoreNotifikasiPerjalananRequest $request): JsonResponse
    {
        $data = $request->validated();
        $jadwal = JadwalDistribusi::query()->with(['titikLayanan', 'armada', 'jadwalProduksi'])->findOrFail($data['jadwal_distribusi_id']);

        $hasil = $this->service->pratinjau(
            $jadwal,
            isset($data['waktu_berangkat']) ? Carbon::parse($data['waktu_berangkat']) : null,
            isset($data['estimasi_menit']) ? (int) $data['estimasi_menit'] : null,
        );

        return response()->json(['data' => [
            'waktu_berangkat' => $this->wib($hasil['waktu_berangkat']),
            'estimasi_tiba' => $this->wib($hasil['estimasi_tiba']),
            'estimasi_menit' => $hasil['estimasi_menit'],
            'estimasi_sumber' => $hasil['estimasi_sumber'],
            'penerima' => $hasil['penerima'],
            'pesan' => $hasil['pesan'],
        ]]);
    }

    public function store(StoreNotifikasiPerjalananRequest $request, AiDraftConfirmer $confirmer): JsonResponse
    {
        $data = $request->validated();
        $jadwal = JadwalDistribusi::query()->with(['titikLayanan', 'armada', 'jadwalProduksi'])->findOrFail($data['jadwal_distribusi_id']);

        $notifikasi = DB::transaction(function () use ($request, $data, $jadwal, $confirmer) {
            $baru = $this->service->mulai(
                $request->user(),
                $jadwal,
                isset($data['waktu_berangkat']) ? Carbon::parse($data['waktu_berangkat']) : null,
                isset($data['estimasi_menit']) ? (int) $data['estimasi_menit'] : null,
            );
            $confirmer->confirm($data['ai_draft_id'] ?? null, $request->user(), 'notifikasi-perjalanan');

            return $baru;
        });

        return (new NotifikasiPerjalananResource($notifikasi->load(['titikLayanan', 'pemicu'])))->response()->setStatusCode(201);
    }

    public function show(NotifikasiPerjalanan $notifikasiPerjalanan): NotifikasiPerjalananResource
    {
        $this->authorize('view', $notifikasiPerjalanan);

        return new NotifikasiPerjalananResource($notifikasiPerjalanan->load(['titikLayanan', 'pemicu']));
    }

    public function ubahStatus(UbahStatusPerjalananRequest $request, NotifikasiPerjalanan $notifikasiPerjalanan, AiDraftConfirmer $confirmer): NotifikasiPerjalananResource
    {
        $data = $request->validated();

        $notifikasi = DB::transaction(function () use ($request, $data, $notifikasiPerjalanan, $confirmer) {
            $hasil = $this->service->ubahStatus(
                $notifikasiPerjalanan,
                StatusPerjalanan::from($data['status']),
                $data['alasan'] ?? null,
            );
            $confirmer->confirm($data['ai_draft_id'] ?? null, $request->user(), 'notifikasi-perjalanan', (int) $hasil->id);

            return $hasil;
        });

        return new NotifikasiPerjalananResource($notifikasi->load(['titikLayanan', 'pemicu']));
    }

    /** @return Builder<NotifikasiPerjalanan> */
    private function cakupan(Request $request): Builder
    {
        $query = NotifikasiPerjalanan::query();
        $user = $request->user();

        // Relawan hanya perjalanan yang ia picu sendiri.
        if ($user->role === Role::Relawan) {
            $query->where('dipicu_oleh', $user->getKey());
        }

        return $query;
    }

    /**
     * @param  Builder<NotifikasiPerjalanan>  $query
     * @param  array<string, mixed>  $data
     */
    private function filter(Builder $query, array $data): void
    {
        foreach (['titik_layanan_id', 'jadwal_distribusi_id'] as $kolom) {
            if (isset($data[$kolom])) {
                $query->where($kolom, (int) $data[$kolom]);
            }
        }

        if (isset($data['status'])) {
            $query->where('status_perjalanan', $data['status']);
        }
    }
}
