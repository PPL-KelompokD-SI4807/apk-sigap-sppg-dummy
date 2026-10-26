<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\MenentukanSppgKeuangan;
use App\Models\PencairanDana;
use App\Models\Pengeluaran;
use App\Services\Keuangan\BudgetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** PBI 14: ringkasan anggaran per periode (Gambar 16). Hak R modul keuangan. */
class KeuanganController extends ApiController
{
    use MenentukanSppgKeuangan;

    public function __construct(private readonly BudgetService $budget) {}

    public function ringkasan(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PencairanDana::class);
        $this->authorize('viewAny', Pengeluaran::class);

        $request->validate([
            'periode' => ['nullable', 'string', 'regex:'.BudgetService::POLA_PERIODE],
            'sppg_id' => [$request->user()->isAdmin() ? 'required' : 'nullable', 'integer'],
        ], [
            'periode.regex' => 'Periode harus berformat YYYY-MM (mis. 2026-11).',
            'sppg_id.required' => 'Admin wajib memilih SPPG (sppg_id).',
        ]);

        $sppgId = $this->sppgRingkasan($request);
        abort_if($sppgId === null || ! DB::table('sppgs')->where('id', $sppgId)->whereNull('deleted_at')->exists(), 404);

        $periode = (string) ($request->query('periode') ?: $this->budget->periodeBerjalan());

        return response()->json(['data' => ['sppg_id' => $sppgId] + $this->budget->ringkasan($sppgId, $periode)]);
    }
}
