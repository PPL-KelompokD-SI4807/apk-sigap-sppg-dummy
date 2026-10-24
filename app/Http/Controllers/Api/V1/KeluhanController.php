<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\KategoriKeluhan;
use App\Enums\PrioritasKeluhan;
use App\Enums\Role;
use App\Enums\StatusKeluhan;
use App\Http\Requests\Keluhan\StoreKeluhanRequest;
use App\Http\Requests\Keluhan\UpdateKeluhanRequest;
use App\Http\Resources\KeluhanResource;
use App\Models\FeedbackKeluhan;
use App\Services\Ai\AiDraftConfirmer;
use App\Services\Keluhan\KeluhanService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class KeluhanController extends ApiController
{
    private const RELASI = ['titikLayanan', 'jadwalProduksi', 'pencatat', 'penangan'];

    public function __construct(private readonly KeluhanService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', FeedbackKeluhan::class);

        $request->validate([
            'status' => ['nullable', Rule::enum(StatusKeluhan::class)],
            'prioritas' => ['nullable', Rule::enum(PrioritasKeluhan::class)],
            'kategori' => ['nullable', Rule::enum(KategoriKeluhan::class)],
            'titik_layanan_id' => ['nullable', 'integer'],
            'jadwal_produksi_id' => ['nullable', 'integer'],
        ]);

        $query = $this->cakupan($request)->with(self::RELASI);

        foreach (['status', 'prioritas', 'kategori', 'titik_layanan_id', 'jadwal_produksi_id'] as $kolom) {
            if ($request->filled($kolom)) {
                $query->where($kolom, $request->query($kolom));
            }
        }

        $this->applySearch($query, $request, ['deskripsi', 'tindak_lanjut']);
        $this->applyDateRange($query, $request, 'created_at');
        $query->orderByDesc('created_at')->orderByDesc('id');

        return KeluhanResource::collection($this->paginate($query, $request));
    }

    public function ringkasan(Request $request): JsonResponse
    {
        $this->authorize('viewAny', FeedbackKeluhan::class);

        $query = $this->applyDateRange($this->cakupan($request), $request, 'created_at');

        return response()->json(['data' => $this->service->ringkasan($query)]);
    }

    public function store(StoreKeluhanRequest $request, AiDraftConfirmer $confirmer): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();
        $sppgId = $user->isAdmin() ? (int) $data['sppg_id'] : (int) $user->sppg_id;

        $keluhan = DB::transaction(function () use ($user, $sppgId, $data, $confirmer) {
            $baru = $this->service->buat($user, $sppgId, $data);
            $confirmer->confirm($data['ai_draft_id'] ?? null, $user, 'keluhan');

            return $baru;
        });

        return (new KeluhanResource($keluhan->load(self::RELASI)))->response()->setStatusCode(201);
    }

    public function show(FeedbackKeluhan $keluhan): KeluhanResource
    {
        $this->authorize('view', $keluhan);

        return new KeluhanResource($keluhan->load(self::RELASI));
    }

    public function update(UpdateKeluhanRequest $request, FeedbackKeluhan $keluhan, AiDraftConfirmer $confirmer): KeluhanResource
    {
        $data = $request->validated();

        $diperbarui = DB::transaction(function () use ($request, $keluhan, $data, $confirmer) {
            $hasil = $this->service->perbarui($request->user(), $keluhan, $data);
            $confirmer->confirm($data['ai_draft_id'] ?? null, $request->user(), 'keluhan', (int) $hasil->id);

            return $hasil;
        });

        return new KeluhanResource($diperbarui->load(self::RELASI));
    }

    public function destroy(FeedbackKeluhan $keluhan): Response
    {
        $this->authorize('delete', $keluhan);
        $keluhan->delete();

        return response()->noContent();
    }

    /** @return Builder<FeedbackKeluhan> */
    private function cakupan(Request $request): Builder
    {
        $query = FeedbackKeluhan::query();
        $user = $request->user();

        if ($user->role === Role::Relawan) {
            $query->where('dicatat_oleh', $user->getKey());
        }

        if ($user->isAdmin() && $request->filled('sppg_id') && ctype_digit((string) $request->query('sppg_id'))) {
            $query->where('sppg_id', (int) $request->query('sppg_id'));
        }

        return $query;
    }
}
