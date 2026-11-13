<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\LaporanBgn\LaporanBgnRequest;
use App\Http\Requests\LaporanBgn\MintaPdfLaporanBgnRequest;
use App\Http\Requests\LaporanBgn\TerbitkanNomorLaporanBgnRequest;
use App\Jobs\KirimPdfLaporanBgn;
use App\Models\Sppg;
use App\Services\LaporanBgn\LaporanBgnPdfService;
use App\Services\LaporanBgn\LaporanBgnService;
use App\Services\LaporanBgn\PenomoranLaporanService;
use Illuminate\Http\JsonResponse;

/**
 * Data laporan BGN Lampiran 30a-30d (R07-2), format JSON untuk render PDF (R07-3) dan layar ekspor (R07-4).
 * Otorisasi (peran, jenis laporan, SPPG) dijalankan LaporanBgnRequest::authorize lewat LaporanBgnPolicy.
 * GET hanya membaca (nomor LPA tampil bila sudah terbit, selain itu null). Nomor diterbitkan lewat POST
 * `.../nomor` (idempoten per SPPG + jenis dokumen + periode) oleh admin, kepala_sppg, akuntan.
 */
class LaporanBgnController extends ApiController
{
    public function __construct(
        private readonly LaporanBgnService $service,
        private readonly LaporanBgnPdfService $pdf,
        private readonly PenomoranLaporanService $penomoran,
    ) {}

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

    /**
     * R07-3: antre pembuatan PDF lalu kirim lewat bot Telegram ke pemohon (202). Nomor LPA TIDAK diterbitkan di sini
     * (terbitkan lebih dulu lewat POST `.../nomor`); bila belum terbit PDF memuat "(belum diterbitkan)".
     */
    public function mintaPdf(MintaPdfLaporanBgnRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $jenis = $request->jenis();
        $sppgId = $user->isAdmin() ? (int) $data['sppg_id'] : (int) $user->sppgAktifId();
        abort_if($sppgId <= 0, 403);

        if ($user->telegram_id === null) {
            return response()->json([
                'message' => 'Akun belum terhubung ke Telegram; PDF dikirim lewat bot sehingga tidak dapat dibuat.',
                'code' => 'telegram_belum_terhubung',
            ], 422);
        }

        [$awal, $akhir] = $request->periode();
        $sppg = Sppg::query()->findOrFail($sppgId);
        $penandatangan = ['akuntan_id' => $data['akuntan_id'] ?? null, 'kepala_sppg_id' => $data['kepala_sppg_id'] ?? null];
        $this->pdf->validasiPenandatangan($sppg, $penandatangan);

        KirimPdfLaporanBgn::dispatch(
            (int) $user->getKey(), (int) $user->telegram_id, $jenis->value, $sppgId,
            $awal->toDateString(), $akhir->toDateString(), $penandatangan,
        );

        $nomor = $jenis->bernomor() ? $this->penomoran->baca($sppg, $jenis, $awal, $akhir)?->nomor : null;

        return response()->json(['data' => [
            'status' => 'antre',
            'jenis' => $jenis->value,
            'lampiran' => $jenis->lampiran(),
            'periode' => ['awal' => $awal->toDateString(), 'akhir' => $akhir->toDateString()],
            'tujuan' => 'telegram',
            'nomor' => $nomor,
            'nomor_terbit' => $nomor !== null,
        ]], 202);
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
