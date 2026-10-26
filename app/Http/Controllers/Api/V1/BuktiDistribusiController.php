<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Controllers\Api\V1\Concerns\MenanganiUnggahan;
use App\Http\Requests\BuktiDistribusi\StoreBuktiDistribusiRequest;
use App\Http\Requests\BuktiDistribusi\UpdateBuktiDistribusiRequest;
use App\Http\Resources\BuktiDistribusi\BuktiDistribusiResource;
use App\Models\BuktiDistribusi;
use App\Services\Ai\AiDraftConfirmer;
use App\Services\Distribusi\BuktiDistribusiService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/** PBI 13 Data Bukti Distribusi (FR-08, UC14). */
class BuktiDistribusiController extends ApiController
{
    use MenanganiUnggahan;

    private const RELASI = ['jadwalDistribusi.titikLayanan', 'pencatat', 'lampirans'];

    public function __construct(private readonly BuktiDistribusiService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', BuktiDistribusi::class);

        $request->validate([
            'terlambat' => ['nullable', 'boolean'],
            'jadwal_distribusi_id' => ['nullable', 'integer'],
            'titik_layanan_id' => ['nullable', 'integer'],
            'tanggal' => ['nullable', 'date_format:Y-m-d'],
            'sppg_id' => ['nullable', 'integer'],
        ]);

        $query = $this->cakupan($request)->with(self::RELASI);

        if ($request->filled('terlambat')) {
            $query->where('terlambat', $request->boolean('terlambat'));
        }

        if ($request->filled('jadwal_distribusi_id')) {
            $query->where('jadwal_distribusi_id', (int) $request->query('jadwal_distribusi_id'));
        }

        if ($request->filled('titik_layanan_id')) {
            $query->whereHas('jadwalDistribusi', fn (Builder $q) => $q->where('titik_layanan_id', (int) $request->query('titik_layanan_id')));
        }

        if ($request->filled('tanggal')) {
            $query->whereHas('jadwalDistribusi', fn (Builder $q) => $q->whereDate('tanggal_distribusi', (string) $request->query('tanggal')));
        }

        $this->applySearch($query, $request, ['nama_pic', 'no_berita_acara']);
        $this->applyDateRange($query, $request, 'waktu_tiba');
        $query->orderByDesc('waktu_tiba')->orderByDesc('id');

        return BuktiDistribusiResource::collection($this->paginate($query, $request));
    }

    public function store(StoreBuktiDistribusiRequest $request, AiDraftConfirmer $confirmer): JsonResponse
    {
        $data = $request->validated();
        $gambar = $this->siapkanGambar($request, ['foto', 'berita_acara']);
        $user = $request->user();

        $bukti = DB::transaction(function () use ($user, $data, $gambar, $confirmer) {
            $baru = $this->service->catat($user, $data, $gambar);
            $confirmer->confirm($data['ai_draft_id'] ?? null, $user, 'bukti-distribusi');

            return $baru;
        });

        return (new BuktiDistribusiResource($bukti->fresh(self::RELASI)))->response()->setStatusCode(201);
    }

    public function show(BuktiDistribusi $buktiDistribusi): BuktiDistribusiResource
    {
        $buktiDistribusi->load(self::RELASI);
        $this->authorize('view', $buktiDistribusi);

        return new BuktiDistribusiResource($buktiDistribusi);
    }

    public function update(UpdateBuktiDistribusiRequest $request, BuktiDistribusi $buktiDistribusi, AiDraftConfirmer $confirmer): BuktiDistribusiResource
    {
        $data = $request->validated();
        $gambar = $this->siapkanGambar($request, ['foto', 'berita_acara']);
        $user = $request->user();

        $hasil = DB::transaction(function () use ($user, $buktiDistribusi, $data, $gambar, $confirmer) {
            $diperbarui = $this->service->perbarui($user, $buktiDistribusi, $data, $gambar);
            $confirmer->confirm($data['ai_draft_id'] ?? null, $user, 'bukti-distribusi', (int) $diperbarui->id);

            return $diperbarui;
        });

        return new BuktiDistribusiResource($hasil->fresh(self::RELASI));
    }

    public function destroy(BuktiDistribusi $buktiDistribusi): Response
    {
        $this->authorize('delete', $buktiDistribusi);
        $this->service->hapus($buktiDistribusi);

        return response()->noContent();
    }

    /** @return Builder<BuktiDistribusi> */
    private function cakupan(Request $request): Builder
    {
        $query = BuktiDistribusi::query();
        $user = $request->user();

        if ($user->role === Role::Relawan) {
            $id = (int) $user->getKey();
            $query->where(fn (Builder $q) => $q->where('dicatat_oleh', $id)
                ->orWhereHas('jadwalDistribusi', fn (Builder $j) => $j->where('petugas_id', $id)));
        }

        if ($user->isAdmin() && $request->filled('sppg_id') && ctype_digit((string) $request->query('sppg_id'))) {
            $query->where('sppg_id', (int) $request->query('sppg_id'));
        }

        return $query;
    }
}
