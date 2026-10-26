<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\MenanganiUnggahan;
use App\Http\Controllers\Api\V1\Concerns\MenentukanSppgKeuangan;
use App\Http\Requests\Keuangan\StorePencairanRequest;
use App\Http\Requests\Keuangan\UpdatePencairanRequest;
use App\Http\Resources\Keuangan\PencairanDanaResource;
use App\Models\PencairanDana;
use App\Services\Ai\AiDraftConfirmer;
use App\Services\Keuangan\BudgetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/** PBI 14 Data Keuangan — pencairan dana (FR-09). Akuntan CRUD; admin/kepala R. */
class PencairanDanaController extends ApiController
{
    use MenanganiUnggahan, MenentukanSppgKeuangan;

    private const RELASI = ['pencatat', 'lampirans'];

    public function __construct(private readonly BudgetService $budget) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PencairanDana::class);
        $request->validate(['periode' => ['nullable', 'string', 'regex:'.BudgetService::POLA_PERIODE], 'sppg_id' => ['nullable', 'integer']]);

        $query = $this->saringSppg(PencairanDana::query()->with(self::RELASI), $request);

        if ($request->filled('periode')) {
            $query->where('periode', (string) $request->query('periode'));
        }

        $this->applySearch($query, $request, ['sumber_dana', 'no_referensi', 'catatan']);
        $this->applyDateRange($query, $request, 'tanggal_cair');
        $query->orderByDesc('tanggal_cair')->orderByDesc('id');

        return PencairanDanaResource::collection($this->paginate($query, $request));
    }

    public function store(StorePencairanRequest $request, AiDraftConfirmer $confirmer): JsonResponse
    {
        $data = $request->validated();
        $bukti = $this->siapkanGambar($request, ['bukti'])['bukti'] ?? null;
        $user = $request->user();

        $pencairan = DB::transaction(function () use ($user, $data, $bukti, $confirmer) {
            $baru = $this->budget->catatPencairan($user, (int) $user->sppg_id, $data, $bukti);
            $confirmer->confirm($data['ai_draft_id'] ?? null, $user, 'keuangan');

            return $baru;
        });

        return (new PencairanDanaResource($pencairan->fresh(self::RELASI)))->response()->setStatusCode(201);
    }

    public function show(PencairanDana $pencairanDana): PencairanDanaResource
    {
        $this->authorize('view', $pencairanDana);

        return new PencairanDanaResource($pencairanDana->load(self::RELASI));
    }

    public function update(UpdatePencairanRequest $request, PencairanDana $pencairanDana, AiDraftConfirmer $confirmer): PencairanDanaResource
    {
        $data = $request->validated();
        $bukti = $this->siapkanGambar($request, ['bukti'])['bukti'] ?? null;
        $user = $request->user();

        $hasil = DB::transaction(function () use ($user, $pencairanDana, $data, $bukti, $confirmer) {
            $diperbarui = $this->budget->ubahPencairan($user, $pencairanDana, $data, $bukti);
            $confirmer->confirm($data['ai_draft_id'] ?? null, $user, 'keuangan', (int) $diperbarui->id);

            return $diperbarui;
        });

        return new PencairanDanaResource($hasil->fresh(self::RELASI));
    }

    public function destroy(PencairanDana $pencairanDana): Response
    {
        $this->authorize('delete', $pencairanDana);
        $this->budget->hapusPencairan($pencairanDana);

        return response()->noContent();
    }
}
