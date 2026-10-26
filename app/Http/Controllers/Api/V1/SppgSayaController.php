<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Sppg;
use App\Support\SppgAktif;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/sppg-saya — daftar SPPG yang boleh dipilih sebagai SPPG aktif (R-06a).
 *
 * - Investor: SPPG miliknya (pivot investor_sppg, belum dihapus). Tetap dilayani walau belum
 *   memilih SPPG aktif (rute pengecualian ResolveSppgAktif).
 * - Peran satu-SPPG: SPPG-nya sendiri.
 * - Admin: seluruh SPPG (lintas SPPG); header X-SPPG-Id tidak dipakai admin.
 * Hanya kolom identitas ringkas (tanpa telepon/alamat) untuk pemilih SPPG.
 */
class SppgSayaController extends ApiController
{
    public function __invoke(Request $request, SppgAktif $sppgAktif): JsonResponse
    {
        $user = $request->user();
        $query = Sppg::query()->orderBy('nama')->orderBy('id');

        if ($user->isInvestor()) {
            $query->whereKey($sppgAktif->milik($user));
        } elseif (! $user->isAdmin()) {
            $user->sppg_id === null ? $query->whereRaw('1 = 0') : $query->whereKey($user->sppg_id);
        }

        $daftar = $query->get(['id', 'kode', 'nama', 'status_operasional']);
        $aktif = $sppgAktif->resolve($user);

        return response()->json([
            'data' => $daftar->map(fn (Sppg $s) => [
                'id' => $s->id,
                'kode' => $s->kode,
                'nama' => $s->nama,
                'status_operasional' => $s->status_operasional->value,
            ])->values(),
            'sppg_aktif_id' => $aktif['id'],
            'wajib_pilih' => $user->isInvestor() && $aktif['id'] === null && $daftar->count() > 1,
            'header' => SppgAktif::HEADER,
        ]);
    }
}
