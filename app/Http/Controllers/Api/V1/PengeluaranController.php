<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\KategoriPengeluaran;
use App\Enums\StatusPersetujuanPengeluaran;
use App\Http\Controllers\Api\V1\Concerns\MenanganiUnggahan;
use App\Http\Controllers\Api\V1\Concerns\MenentukanSppgKeuangan;
use App\Http\Requests\Keuangan\KeputusanPengeluaranRequest;
use App\Http\Requests\Keuangan\StorePengeluaranRequest;
use App\Http\Requests\Keuangan\UpdatePengeluaranRequest;
use App\Http\Resources\Keuangan\PengeluaranResource;
use App\Models\Pengeluaran;
use App\Services\Ai\AiDraftConfirmer;
use App\Services\Keuangan\BudgetService;
use App\Services\Persetujuan\PersetujuanPengeluaranService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** PBI 14 Data Keuangan — pengeluaran operasional (FR-09). Akuntan CRUD; admin/kepala R. */
class PengeluaranController extends ApiController
{
    use MenanganiUnggahan, MenentukanSppgKeuangan;

    private const RELASI = ['pencatat', 'pemasok', 'lampirans'];

    private const RELASI_DETAIL = ['pencatat', 'pemasok', 'lampirans', 'persetujuans.pengaju', 'persetujuans.pemutus'];

    public function __construct(
        private readonly BudgetService $budget,
        private readonly PersetujuanPengeluaranService $persetujuan,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Pengeluaran::class);
        $request->validate([
            'periode' => ['nullable', 'string', 'regex:'.BudgetService::POLA_PERIODE],
            'kategori' => ['nullable', Rule::enum(KategoriPengeluaran::class)],
            'pemasok_id' => ['nullable', 'integer'],
            'pencairan_dana_id' => ['nullable', 'integer'],
            'melebihi_anggaran' => ['nullable', 'boolean'],
            'status_persetujuan' => ['nullable', Rule::enum(StatusPersetujuanPengeluaran::class)],
            'sppg_id' => ['nullable', 'integer'],
        ]);

        $query = $this->saringSppg(Pengeluaran::query()->with(self::RELASI), $request);

        if ($request->filled('periode')) {
            $awal = Carbon::createFromFormat('Y-m-d', $request->query('periode').'-01');
            $query->whereDate('tanggal', '>=', $awal->toDateString())->whereDate('tanggal', '<=', $awal->copy()->endOfMonth()->toDateString());
        }

        foreach (['kategori', 'pemasok_id', 'pencairan_dana_id', 'status_persetujuan'] as $kolom) {
            if ($request->filled($kolom)) {
                $query->where($kolom, $request->query($kolom));
            }
        }

        if ($request->filled('melebihi_anggaran')) {
            $query->where('melebihi_anggaran', $request->boolean('melebihi_anggaran'));
        }

        $this->applySearch($query, $request, ['uraian']);
        $this->applyDateRange($query, $request, 'tanggal');
        $query->orderByDesc('tanggal')->orderByDesc('id');

        return PengeluaranResource::collection($this->paginate($query, $request));
    }

    public function store(StorePengeluaranRequest $request, AiDraftConfirmer $confirmer): JsonResponse
    {
        $data = $request->validated();
        $nota = $this->siapkanGambar($request, ['nota'])['nota'] ?? null;
        $user = $request->user();

        $pengeluaran = DB::transaction(function () use ($user, $data, $nota, $confirmer) {
            $baru = $this->budget->catatPengeluaran($user, (int) $user->sppg_id, $data, $nota);
            $confirmer->confirm($data['ai_draft_id'] ?? null, $user, 'keuangan', resource: 'pengeluaran');

            return $baru;
        });

        return (new PengeluaranResource($pengeluaran->fresh(self::RELASI_DETAIL)))->response()->setStatusCode(201);
    }

    public function show(Pengeluaran $pengeluaran): PengeluaranResource
    {
        $this->authorize('view', $pengeluaran);

        return new PengeluaranResource($pengeluaran->load(self::RELASI_DETAIL));
    }

    public function update(UpdatePengeluaranRequest $request, Pengeluaran $pengeluaran, AiDraftConfirmer $confirmer): PengeluaranResource
    {
        $data = $request->validated();
        $nota = $this->siapkanGambar($request, ['nota'])['nota'] ?? null;
        $user = $request->user();

        $hasil = DB::transaction(function () use ($user, $pengeluaran, $data, $nota, $confirmer) {
            $diperbarui = $this->budget->ubahPengeluaran($user, $pengeluaran, $data, (int) $data['versi'], $nota);
            $confirmer->confirm($data['ai_draft_id'] ?? null, $user, 'keuangan', (int) $diperbarui->id, resource: 'pengeluaran');

            return $diperbarui;
        });

        return new PengeluaranResource($hasil->fresh(self::RELASI_DETAIL));
    }

    /** Keputusan investor (R-06b-2): setujui/tolak pengajuan pengeluaran; satu-satunya tulis investor. */
    public function keputusan(KeputusanPengeluaranRequest $request, Pengeluaran $pengeluaran): PengeluaranResource
    {
        $data = $request->validated();

        $hasil = $this->persetujuan->putuskan($request->user(), $pengeluaran, $data['keputusan'], $data['catatan'] ?? null, (int) $data['versi']);

        return new PengeluaranResource($hasil->fresh(self::RELASI_DETAIL));
    }

    public function destroy(Pengeluaran $pengeluaran): Response
    {
        $this->authorize('delete', $pengeluaran);
        $this->budget->hapusPengeluaran($pengeluaran);

        return response()->noContent();
    }
}
