<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\KategoriPengeluaran;
use App\Http\Controllers\Api\V1\Concerns\MenanganiUnggahan;
use App\Http\Controllers\Api\V1\Concerns\MenentukanSppgKeuangan;
use App\Http\Requests\Keuangan\StorePengeluaranRequest;
use App\Http\Requests\Keuangan\UpdatePengeluaranRequest;
use App\Http\Resources\Keuangan\PengeluaranResource;
use App\Models\Pengeluaran;
use App\Services\Ai\AiDraftConfirmer;
use App\Services\Keuangan\BudgetService;
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

    public function __construct(private readonly BudgetService $budget) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Pengeluaran::class);
        $request->validate([
            'periode' => ['nullable', 'string', 'regex:'.BudgetService::POLA_PERIODE],
            'kategori' => ['nullable', Rule::enum(KategoriPengeluaran::class)],
            'pemasok_id' => ['nullable', 'integer'],
            'pencairan_dana_id' => ['nullable', 'integer'],
            'melebihi_anggaran' => ['nullable', 'boolean'],
            'sppg_id' => ['nullable', 'integer'],
        ]);

        $query = $this->saringSppg(Pengeluaran::query()->with(self::RELASI), $request);

        if ($request->filled('periode')) {
            $awal = Carbon::createFromFormat('Y-m-d', $request->query('periode').'-01');
            $query->whereDate('tanggal', '>=', $awal->toDateString())->whereDate('tanggal', '<=', $awal->copy()->endOfMonth()->toDateString());
        }

        foreach (['kategori', 'pemasok_id', 'pencairan_dana_id'] as $kolom) {
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

        return (new PengeluaranResource($pengeluaran->fresh(self::RELASI)))->response()->setStatusCode(201);
    }

    public function show(Pengeluaran $pengeluaran): PengeluaranResource
    {
        $this->authorize('view', $pengeluaran);

        return new PengeluaranResource($pengeluaran->load(self::RELASI));
    }

    public function update(UpdatePengeluaranRequest $request, Pengeluaran $pengeluaran, AiDraftConfirmer $confirmer): PengeluaranResource
    {
        $data = $request->validated();
        $nota = $this->siapkanGambar($request, ['nota'])['nota'] ?? null;
        $user = $request->user();

        $hasil = DB::transaction(function () use ($user, $pengeluaran, $data, $nota, $confirmer) {
            $diperbarui = $this->budget->ubahPengeluaran($user, $pengeluaran, $data, $nota);
            $confirmer->confirm($data['ai_draft_id'] ?? null, $user, 'keuangan', (int) $diperbarui->id, resource: 'pengeluaran');

            return $diperbarui;
        });

        return new PengeluaranResource($hasil->fresh(self::RELASI));
    }

    public function destroy(Pengeluaran $pengeluaran): Response
    {
        $this->authorize('delete', $pengeluaran);
        $this->budget->hapusPengeluaran($pengeluaran);

        return response()->noContent();
    }
}
