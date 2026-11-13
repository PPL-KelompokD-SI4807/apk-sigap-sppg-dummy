<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\LaporanBgn\LaporanBgnRequest;
use App\Models\Sppg;
use App\Services\LaporanBgn\LaporanBgnService;
use Illuminate\Http\JsonResponse;

/**
 * Data laporan BGN Lampiran 30a-30d (R07-2), format JSON untuk render PDF (R07-3) dan layar ekspor (R07-4).
 * Otorisasi (peran, jenis laporan, SPPG) dijalankan LaporanBgnRequest::authorize lewat LaporanBgnPolicy.
 * GET idempoten: periode yang sama mengembalikan nomor LPA yang sama (nomor baru hanya diterbitkan untuk
 * admin, kepala_sppg, akuntan pada panggilan pertama).
 */
class LaporanBgnController extends ApiController
{
    public function __construct(private readonly LaporanBgnService $service) {}

    public function porsi(LaporanBgnRequest $request): JsonResponse
    {
        return $this->tampil($request);
    }

    public function harian(LaporanBgnRequest $request): JsonResponse
    {
        return $this->tampil($request);
    }

    public function duaMingguan(LaporanBgnRequest $request): JsonResponse
    {
        return $this->tampil($request);
    }

    public function bulananRingkasan(LaporanBgnRequest $request): JsonResponse
    {
        return $this->tampil($request);
    }

    public function bulananAnggaran(LaporanBgnRequest $request): JsonResponse
    {
        return $this->tampil($request);
    }

    public function bulananPenerima(LaporanBgnRequest $request): JsonResponse
    {
        return $this->tampil($request);
    }

    private function tampil(LaporanBgnRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $jenis = $request->jenis();
        $sppgId = $user->isAdmin() ? (int) $data['sppg_id'] : (int) $user->sppgAktifId();
        abort_if($sppgId <= 0, 403);

        [$awal, $akhir] = $request->periode();

        $hasil = $this->service->susun(
            $jenis,
            Sppg::query()->findOrFail($sppgId),
            $awal,
            $akhir,
            $user,
            ['akuntan_id' => $data['akuntan_id'] ?? null, 'kepala_sppg_id' => $data['kepala_sppg_id'] ?? null],
        );

        return response()->json(['data' => $hasil]);
    }
}
