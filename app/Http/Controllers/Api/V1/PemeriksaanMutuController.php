<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\HasilMutu;
use App\Http\Requests\Mutu\StorePemeriksaanMutuRequest;
use App\Http\Requests\Mutu\UpdatePemeriksaanMutuRequest;
use App\Http\Resources\Mutu\PemeriksaanMutuResource;
use App\Models\PemeriksaanMutu;
use App\Services\Ai\AiDraftConfirmer;
use App\Services\Mutu\PemeriksaanMutuService;
use App\Services\Storage\PengikatLampiran;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/** Modul 11 Data Pemeriksaan Mutu dan Keamanan Pangan (FR-07). */
class PemeriksaanMutuController extends ApiController
{
    private const MODULE = 'mutu';

    private const RELASI = ['jadwalProduksi:id,menu_id,tanggal_produksi,status', 'jadwalProduksi.menu:id,nama_menu', 'pemeriksa:id,name', 'lampirans'];

    public function __construct(
        private readonly PemeriksaanMutuService $service,
        private readonly AiDraftConfirmer $draf,
        private readonly PengikatLampiran $lampiran,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PemeriksaanMutu::class);

        $query = PemeriksaanMutu::query()->with(self::RELASI);
        $this->applySearch($query, $request, ['catatan_organoleptik', 'tindakan_korektif']);
        $this->applyDateRange($query, $request, 'waktu_pemeriksaan');

        if (in_array($request->query('hasil'), HasilMutu::values(), true)) {
            $query->where('hasil', $request->query('hasil'));
        }

        foreach (['jadwal_produksi_id', 'pemeriksa_id'] as $kolom) {
            if (is_numeric($request->query($kolom))) {
                $query->where($kolom, (int) $request->query($kolom));
            }
        }

        if ($request->user()->isAdmin() && is_numeric($request->query('sppg_id'))) {
            $query->where('sppg_id', (int) $request->query('sppg_id'));
        }

        return PemeriksaanMutuResource::collection($this->paginate($query->orderByDesc('waktu_pemeriksaan')->orderByDesc('id'), $request));
    }

    public function store(StorePemeriksaanMutuRequest $request): JsonResponse
    {
        $this->authorize('create', PemeriksaanMutu::class);
        $data = $request->validated();

        $pemeriksaan = DB::transaction(function () use ($request, $data): PemeriksaanMutu {
            $pemeriksaan = $this->service->catat($request->user(), $data);
            $this->lampiran->ikat($data['lampiran_ids'] ?? [], $pemeriksaan, self::MODULE, $request->user());
            $this->draf->confirm($data['ai_draft_id'] ?? null, $request->user(), self::MODULE);

            return $pemeriksaan;
        });

        return (new PemeriksaanMutuResource($this->muat($pemeriksaan)))->response()->setStatusCode(201);
    }

    public function show(PemeriksaanMutu $pemeriksaanMutu): PemeriksaanMutuResource
    {
        $this->authorize('view', $pemeriksaanMutu);

        return new PemeriksaanMutuResource($this->muat($pemeriksaanMutu));
    }

    public function update(UpdatePemeriksaanMutuRequest $request, PemeriksaanMutu $pemeriksaanMutu): PemeriksaanMutuResource
    {
        $this->authorize('update', $pemeriksaanMutu);
        $data = $request->validated();

        $diubah = DB::transaction(function () use ($request, $pemeriksaanMutu, $data): PemeriksaanMutu {
            $diubah = $this->service->perbarui($pemeriksaanMutu, $request->user(), $data);
            $this->lampiran->ikat($data['lampiran_ids'] ?? [], $diubah, self::MODULE, $request->user());
            $this->draf->confirm($data['ai_draft_id'] ?? null, $request->user(), self::MODULE, $diubah->id);

            return $diubah;
        });

        return new PemeriksaanMutuResource($this->muat($diubah));
    }

    public function destroy(PemeriksaanMutu $pemeriksaanMutu): Response
    {
        $this->authorize('delete', $pemeriksaanMutu);
        $this->service->hapus($pemeriksaanMutu);

        return response()->noContent();
    }

    private function muat(PemeriksaanMutu $pemeriksaan): PemeriksaanMutu
    {
        $pemeriksaan->load(self::RELASI);
        $pemeriksaan->setAttribute('memengaruhi_distribusi', $this->service->memengaruhiDistribusi($pemeriksaan));

        return $pemeriksaan;
    }
}
