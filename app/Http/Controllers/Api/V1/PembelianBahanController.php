<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StatusPembelianBahan;
use App\Http\Requests\Pembelian\StorePembelianBahanRequest;
use App\Http\Requests\Pembelian\UpdatePembelianBahanRequest;
use App\Http\Requests\Pembelian\VerifikasiPembelianBahanRequest;
use App\Http\Resources\Pembelian\PembelianBahanResource;
use App\Models\PembelianBahan;
use App\Services\Ai\AiDraftConfirmer;
use App\Services\Pembelian\PembelianBahanService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Pembelian bahan (R-06b-2). Staf gudang CRU, akuntan memverifikasi, admin/kepala/akuntan R; investor
 * tanpa akses (403). Tanpa endpoint hapus. Nota diunggah lewat POST /lampiran (modul pembelian-bahan, tertunda).
 */
class PembelianBahanController extends ApiController
{
    private const MODULE = 'pembelian-bahan';

    private const RELASI = ['bahanBaku', 'pemasok', 'pencatat:id,name', 'pemverifikasi:id,name', 'lampirans'];

    public function __construct(
        private readonly PembelianBahanService $service,
        private readonly AiDraftConfirmer $draf,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PembelianBahan::class);

        $query = PembelianBahan::query()->with(self::RELASI);

        $status = $request->query('status');

        if (is_string($status) && in_array($status, StatusPembelianBahan::values(), true)) {
            $query->where('status', $status);
        }

        foreach (['bahan_baku_id', 'pemasok_id'] as $kolom) {
            if (($id = filter_var($request->query($kolom), FILTER_VALIDATE_INT)) !== false) {
                $query->where($kolom, $id);
            }
        }

        if ($request->user()->isAdmin() && ($sppgId = filter_var($request->query('sppg_id'), FILTER_VALIDATE_INT)) !== false) {
            $query->where('sppg_id', $sppgId);
        }

        $term = trim((string) $request->query('q', ''));

        if ($term !== '') {
            $like = '%'.addcslashes(mb_substr($term, 0, 100), '\\%_').'%';
            $query->where(function (Builder $q) use ($like): void {
                $q->whereHas('bahanBaku', fn (Builder $b) => $b->where('nama', 'like', $like))
                    ->orWhereHas('pemasok', fn (Builder $p) => $p->where('nama', 'like', $like));
            });
        }

        $this->applyDateRange($query, $request, 'tanggal_beli');

        return PembelianBahanResource::collection($this->paginate($query->orderByDesc('tanggal_beli')->orderByDesc('id'), $request));
    }

    public function store(StorePembelianBahanRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        $pembelian = DB::transaction(function () use ($user, $data): PembelianBahan {
            $baru = $this->service->catat($user, $data);
            $this->draf->confirm($data['ai_draft_id'] ?? null, $user, self::MODULE);

            return $baru;
        });

        return (new PembelianBahanResource($pembelian->load(self::RELASI)))->response()->setStatusCode(201);
    }

    public function show(PembelianBahan $pembelianBahan): PembelianBahanResource
    {
        $this->authorize('view', $pembelianBahan);

        $pembelianBahan->load(self::RELASI);
        $pembelianBahan->setRelation('hargaReferensi', $this->service->hargaReferensi($pembelianBahan));

        return new PembelianBahanResource($pembelianBahan);
    }

    public function update(UpdatePembelianBahanRequest $request, PembelianBahan $pembelianBahan): PembelianBahanResource
    {
        $data = $request->validated();
        $user = $request->user();

        $hasil = DB::transaction(function () use ($user, $pembelianBahan, $data): PembelianBahan {
            $diubah = $this->service->ubah($user, $pembelianBahan, $data, (int) $data['versi']);
            $this->draf->confirm($data['ai_draft_id'] ?? null, $user, self::MODULE, (int) $diubah->getKey());

            return $diubah;
        });

        return new PembelianBahanResource($hasil->load(self::RELASI));
    }

    public function verifikasi(VerifikasiPembelianBahanRequest $request, PembelianBahan $pembelianBahan): PembelianBahanResource
    {
        $data = $request->validated();

        $hasil = $this->service->verifikasi($request->user(), $pembelianBahan, $data['keputusan'], $data['alasan'] ?? null, (int) $data['versi']);

        return new PembelianBahanResource($hasil->load(self::RELASI));
    }
}
