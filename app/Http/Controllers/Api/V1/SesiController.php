<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Auth\UbahPasswordRequest;
use App\Services\Auth\AuditAuth;
use App\Services\Auth\BrowserTepercayaService;
use App\Services\Auth\KredensialService;
use App\Services\Auth\SesiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Sesi/perangkat aktif dan password milik pengguna login (R-03). Selalu dibatasi ke akun sendiri.
 */
class SesiController extends ApiController
{
    public function __construct(
        private readonly SesiService $sesi,
        private readonly BrowserTepercayaService $browser,
    ) {}

    /**
     * GET /api/v1/auth/sesi — sesi Mini App + web milik sendiri. Tiap item ber-`jenis` (miniapp|web);
     * `meta` memuat jenis sesi ini dan kuota per jenis (R-04 Q2).
     */
    public function index(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        return response()->json([
            'data' => $this->sesi->daftarSesi($request->user(), $this->idTokenIni($request)),
            'meta' => [
                'jenis_sesi_ini' => $token instanceof PersonalAccessToken ? SesiService::jenis($token) : SesiService::JENIS_MINIAPP,
                'kuota' => [
                    SesiService::JENIS_MINIAPP => $this->sesi->maksSesi(),
                    SesiService::JENIS_WEB => $this->sesi->maksSesiWeb(),
                    'browser_tepercaya' => $this->browser->maks(),
                ],
            ],
        ]);
    }

    /**
     * GET /api/v1/auth/browser-tepercaya — browser web tepercaya BERLAKU milik sendiri (R04-3). Tanpa selektor/
     * validator; `browser_ini` = browser sesi web yang sedang dipakai.
     */
    public function browserTepercaya(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => $this->browser->daftar($user, $this->idBrowserIni($request)),
            'meta' => ['kuota' => $this->browser->maks(), 'masa_hari' => $this->browser->masaHari()],
        ]);
    }

    /**
     * DELETE /api/v1/auth/browser-tepercaya/{browser} — cabut satu browser tepercaya milik sendiri beserta sesi web
     * turunannya (milik akun lain/sudah dicabut: 404). Browser ini: cookie `__Host-sigap_tb` ikut dihapus.
     */
    public function cabutBrowserTepercaya(Request $request, int $browser): JsonResponse
    {
        $user = $request->user();
        $browserIni = $this->idBrowserIni($request);
        $dicabut = $this->browser->cabutSatu($user, $browser) ?? throw new NotFoundHttpException;
        $ini = (int) $dicabut->getKey() === $browserIni;

        $respons = response()->json([
            'message' => $ini ? 'Browser ini tidak lagi tepercaya dan sesinya diakhiri. Silakan masuk kembali.' : 'Browser tepercaya dicabut.',
            'browser_ini' => $ini,
        ]);

        return $ini ? $respons->withCookie(BrowserTepercayaService::lupakanCookie()) : $respons;
    }

    /** DELETE /api/v1/auth/sesi/{sesi} — cabut satu sesi milik sendiri (milik akun lain: 404). */
    public function destroy(Request $request, int $sesi): JsonResponse
    {
        $user = $request->user();
        $token = $this->sesi->cabutSatu($user, $sesi) ?? throw new NotFoundHttpException;
        $sesiIni = (int) $token->getKey() === $this->idTokenIni($request);
        AuditAuth::catat($user, AuditAuth::SESI_DICABUT, ['sesi_ini' => $sesiIni, 'jenis' => SesiService::jenis($token)]);

        return response()->json([
            'message' => $sesiIni ? 'Sesi perangkat ini diakhiri. Silakan masuk kembali.' : 'Sesi perangkat dicabut.',
            'sesi_ini' => $sesiIni,
        ]);
    }

    /** POST /api/v1/auth/keluar-semua — cabut semua sesi termasuk perangkat ini. */
    public function keluarSemua(Request $request): JsonResponse
    {
        $user = $request->user();
        $jumlah = $this->sesi->keluarSemua($user);
        AuditAuth::catat($user, AuditAuth::KELUAR_SEMUA, ['jumlah_sesi' => $jumlah]);

        return response()->json([
            'message' => 'Berhasil keluar dari semua perangkat. Silakan masuk kembali.',
            'jumlah_sesi' => $jumlah,
        ]);
    }

    /** PUT /api/v1/auth/password — ubah password; sesi ini tetap, sesi lain dicabut. */
    public function ubahPassword(UbahPasswordRequest $request, KredensialService $kredensial): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if (! $token instanceof PersonalAccessToken || ! $token->exists) {
            abort(403);
        }

        $jumlah = $kredensial->ubahPassword(
            $request->user(),
            (string) $request->validated('password_lama'),
            (string) $request->validated('password'),
            $token,
        );

        return response()->json([
            'message' => 'Password diubah. Sesi di perangkat lain telah dicabut.',
            'jumlah_sesi_dicabut' => $jumlah,
        ]);
    }

    private function idTokenIni(Request $request): ?int
    {
        $token = $request->user()->currentAccessToken();

        return $token instanceof PersonalAccessToken && $token->exists ? (int) $token->getKey() : null;
    }

    /** Browser tepercaya tempat sesi web ini terbit (null untuk Mini App/sesi web biasa). */
    private function idBrowserIni(Request $request): ?int
    {
        $token = $request->user()->currentAccessToken();
        $id = $token instanceof PersonalAccessToken && SesiService::tokenWeb($token) ? $token->getAttribute('browser_tepercaya_id') : null;

        return $id === null ? null : (int) $id;
    }
}
