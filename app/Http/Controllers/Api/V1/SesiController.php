<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Auth\UbahPasswordRequest;
use App\Services\Auth\AuditAuth;
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
    public function __construct(private readonly SesiService $sesi) {}

    /** GET /api/v1/auth/sesi */
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->sesi->daftarSesi($request->user(), $this->idTokenIni($request))]);
    }

    /** DELETE /api/v1/auth/sesi/{sesi} — cabut satu sesi milik sendiri (milik akun lain: 404). */
    public function destroy(Request $request, int $sesi): JsonResponse
    {
        $user = $request->user();
        $token = $this->sesi->cabutSatu($user, $sesi) ?? throw new NotFoundHttpException;
        $sesiIni = (int) $token->getKey() === $this->idTokenIni($request);
        AuditAuth::catat($user, AuditAuth::SESI_DICABUT, ['sesi_ini' => $sesiIni]);

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
}
