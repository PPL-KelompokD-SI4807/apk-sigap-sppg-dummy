<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Auth\TelegramLoginRequest;
use App\Http\Resources\AuthUserResource;
use App\Services\Auth\AuditAuth;
use App\Services\Auth\SesiService;
use App\Services\Auth\SesiWeb;
use App\Services\Telegram\TelegramAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Lapis 1 (initData) + profil/logout sesi login penuh (R-03).
 */
class AuthController extends ApiController
{
    /**
     * POST /api/v1/auth/telegram — validasi initData setiap Mini App dibuka.
     * Mengembalikan langkah berikutnya: sesi_aktif | masuk | aktivasi (+ token pra-login).
     */
    public function telegram(TelegramLoginRequest $request, TelegramAuthService $auth): JsonResponse
    {
        $hasil = $auth->login(
            (string) $request->validated('init_data'),
            $request->validated('device_id'),
            $request->bearerToken(),
        );

        $user = $hasil['user'];

        if ($hasil['langkah'] === 'sesi_aktif') {
            return response()->json([
                'langkah' => 'sesi_aktif',
                'expires_at' => $hasil['expires_at']?->toIso8601String(),
                'user' => (new AuthUserResource($user))->resolve($request),
            ]);
        }

        return response()->json([
            'langkah' => $hasil['langkah'],
            'token' => $hasil['token'],
            'token_type' => 'Bearer',
            'token_jenis' => 'pra_login',
            'expires_at' => $hasil['expires_at']?->toIso8601String(),
            'akun' => [
                'nama' => $user->name,
                'perlu_username' => $user->username === null,
            ],
        ]);
    }

    /** GET /api/v1/auth/me */
    public function me(Request $request, SesiService $sesi): JsonResponse
    {
        $user = $request->user();
        $token = $user->currentAccessToken();
        $tersimpan = $token instanceof PersonalAccessToken && $token->exists;

        $body = [
            'user' => (new AuthUserResource($user))->resolve($request),
            // Masa sesi bergeser saat ini (sudah termasuk perpanjangan request ini) dan batas absolutnya.
            'expires_at' => $tersimpan ? $sesi->kedaluwarsaEfektif($token, $user)->toIso8601String() : null,
            // min(login + 180 hari, akhir kepercayaan perangkat = token bot terakhir + 180 hari).
            'batas_absolut_at' => $tersimpan ? $sesi->batasAkhir($token, $user)->toIso8601String() : null,
        ];

        // Jalur cookie web (R-04): token CSRF sesi ini untuk disimpan di memori JS (bukan cookie/storage),
        // dipakai ulang setelah halaman dimuat ulang. Respons Mini App (Bearer) tidak berubah.
        $cookie = $request->attributes->get(SesiWeb::ATRIBUT_COOKIE);

        if ($tersimpan && SesiWeb::jalurCookie($request) && is_string($cookie)) {
            $body['jenis_sesi'] = SesiService::JENIS_WEB;
            $body['csrf'] = SesiWeb::csrfSesi($cookie);
        }

        return response()->json($body);
    }

    /** POST /api/v1/auth/logout — akhiri sesi perangkat ini (kepercayaan perangkat ikut dicabut). */
    public function logout(Request $request, SesiService $sesi): JsonResponse
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken && $token->exists) {
            $sesi->logout($user, $token);
            AuditAuth::catat($user, AuditAuth::LOGOUT, ['jenis' => SesiService::jenis($token)]);
        }

        return response()->json(['message' => 'Berhasil keluar.']);
    }
}
