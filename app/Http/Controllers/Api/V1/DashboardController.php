<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Dashboard\DashboardService;
use App\Support\SppgAktif;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * GET /api/v1/dashboard (R-07): satu endpoint, isi ditentukan peran pengguna.
 *
 * Query: `rentang` = 7|30 (tren, bawaan 7); `sppg_id` = pilih satu SPPG (investor: hanya SPPG miliknya, bila
 * tidak dikirim dan tanpa header X-SPPG-Id -> gabungan; Admin: opsional; peran lain: hanya SPPG sendiri).
 * SPPG asing -> 403 `sppg_aktif_ditolak`.
 */
class DashboardController extends ApiController
{
    public function __construct(private readonly DashboardService $service) {}

    public function __invoke(Request $request): JsonResponse
    {
        Gate::authorize('dashboard.lihat');

        $data = $request->validate([
            'rentang' => ['nullable', 'integer', 'in:'.implode(',', DashboardService::RENTANG_DIIZINKAN)],
            'sppg_id' => ['nullable', 'string', 'regex:/^[1-9][0-9]{0,16}$/'],
        ], [
            'rentang.in' => 'Rentang harus 7 atau 30 hari.',
            'sppg_id.regex' => 'sppg_id harus bilangan bulat positif.',
        ]);

        $sppgId = isset($data['sppg_id']) ? SppgAktif::idDariHeader($data['sppg_id']) : null;

        return response()->json(['data' => $this->service->bangun(
            $request->user(),
            $sppgId,
            (int) ($data['rentang'] ?? DashboardService::RENTANG_BAWAAN),
        )]);
    }
}
