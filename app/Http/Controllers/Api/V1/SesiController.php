<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Auth\AuditAuth;
use App\Services\Auth\SesiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sesi/perangkat aktif milik pengguna login (R-03). Selalu dibatasi ke akun sendiri.
 */
class SesiController extends ApiController
{
    public function __construct(private readonly SesiService $sesi) {}

    /** GET /api/v1/auth/sesi */
    public function index(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        $id = $token instanceof PersonalAccessToken && $token->exists ? (int) $token->getKey() : null;

        return response()->json(['data' => $this->sesi->daftarSesi($request->user(), $id)]);
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
}
