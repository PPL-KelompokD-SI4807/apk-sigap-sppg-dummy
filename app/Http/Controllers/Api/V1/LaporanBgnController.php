<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\LaporanBgn\LaporanBgnRequest;
use App\Http\Requests\LaporanBgn\TerbitkanNomorLaporanBgnRequest;
use App\Models\Sppg;
use App\Services\LaporanBgn\LaporanBgnService;
use Illuminate\Http\JsonResponse;

/**
 * Data laporan BGN Lampiran 30a-30d (R07-2), format JSON untuk render PDF (R07-3) dan layar ekspor (R07-4).
 * Otorisasi (peran, jenis laporan, SPPG) dijalankan LaporanBgnRequest::authorize lewat LaporanBgnPolicy.
 * GET hanya membaca (nomor LPA tampil bila sudah terbit, selain itu null). Nomor diterbitkan lewat POST
 * `.../nomor` (idempoten per SPPG + jenis dokumen + periode) oleh admin, kepala_sppg, akuntan.
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

    public function terbitkanNomor(TerbitkanNomorLaporanBgnRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $sppgId = $user->isAdmin() ? (int) $data['sppg_id'] : (int) $user->sppgAktifId();
        abort_if($sppgId <= 0, 403);

        [$awal, $akhir] = $request->periode();

        $hasil = $this->service->terbitkanNomor($request->jenis(), Sppg::query()->findOrFail($sppgId), $awal, $akhir, $user);

        return response()->json(['data' => $hasil]);
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
