<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Auth\AktivasiRequest;
use App\Http\Requests\Auth\KirimUlangTokenRequest;
use App\Http\Requests\Auth\MasukRequest;
use App\Http\Requests\Auth\VerifikasiTokenRequest;
use App\Http\Resources\AuthUserResource;
use App\Models\TantanganToken;
use App\Models\User;
use App\Services\Auth\KredensialService;
use App\Services\Auth\SesiService;
use App\Services\Auth\TokenSekaliPakaiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Lapis 2 login R-03 (hanya token pra-login, middleware kemampuan:auth:pra-login):
 * masuk, aktivasi, verifikasi & kirim ulang token 6 digit. Tidak ada reset password mandiri:
 * lupa password = hubungi Admin (POST /pengguna/{id}/reset-password) atau sigap:reset-password-admin.
 */
class KredensialController extends ApiController
{
    public function __construct(private readonly KredensialService $kredensial) {}

    /** POST /api/v1/auth/masuk */
    public function masuk(MasukRequest $request): JsonResponse
    {
        $hasil = $this->kredensial->masuk(
            $request->user(),
            (string) $request->validated('identitas'),
            (string) $request->validated('password'),
            SesiService::hashPerangkat((string) $request->validated('device_id')),
            $request->validated('label_perangkat'),
            $this->praLogin($request),
        );

        return $hasil['langkah'] === 'selesai'
            ? $this->loginPenuh($hasil['token'], $hasil['user'], $request)
            : $this->tantangan($hasil['tantangan']);
    }

    /** POST /api/v1/auth/aktivasi */
    public function aktivasi(AktivasiRequest $request): JsonResponse
    {
        $tantangan = $this->kredensial->mulaiAktivasi(
            $request->user(),
            $request->safe()->only(['username', 'phone', 'password']),
            SesiService::hashPerangkat((string) $request->validated('device_id')),
            $request->validated('label_perangkat'),
            $this->praLogin($request),
        );

        return $this->tantangan($tantangan);
    }

    /** POST /api/v1/auth/token/verifikasi */
    public function verifikasiToken(VerifikasiTokenRequest $request): JsonResponse
    {
        $hasil = $this->kredensial->selesaikan(
            $request->user(),
            (string) $request->validated('tantangan_id'),
            (string) $request->validated('kode'),
            SesiService::hashPerangkat((string) $request->validated('device_id')),
            $this->praLogin($request),
        );

        return $this->loginPenuh($hasil['token'], $hasil['user'], $request, $hasil['tujuan']);
    }

    /** POST /api/v1/auth/token/kirim-ulang */
    public function kirimUlangToken(KirimUlangTokenRequest $request, TokenSekaliPakaiService $token): JsonResponse
    {
        $tantangan = $token->kirimUlang(
            $request->user(),
            $this->praLogin($request),
            (string) $request->validated('tantangan_id'),
            SesiService::hashPerangkat((string) $request->validated('device_id')),
        );

        return $this->tantangan($tantangan);
    }

    private function praLogin(Request $request): PersonalAccessToken
    {
        /** @var PersonalAccessToken $token dijamin middleware kemampuan:auth:pra-login */
        $token = $request->user()->currentAccessToken();

        return $token;
    }

    private function loginPenuh(string $token, User $user, Request $request, ?string $tujuan = null): JsonResponse
    {
        // expires_at selalu null: sesi login penuh tidak kedaluwarsa (berakhir hanya karena dicabut).
        return response()->json([
            'langkah' => 'selesai',
            'tujuan' => $tujuan ?? 'masuk',
            'token' => $token,
            'token_type' => 'Bearer',
            'token_jenis' => 'penuh',
            'expires_at' => null,
            'user' => (new AuthUserResource($user))->resolve($request),
        ]);
    }

    private function tantangan(TantanganToken $tantangan): JsonResponse
    {
        $jeda = (int) config('kredensial.token.jeda_kirim_ulang_detik', 60);
        $sisaKirimUlang = max(0, (int) config('kredensial.token.maks_kirim_ulang', 3) - $tantangan->kirim_ulang);

        return response()->json([
            'langkah' => 'verifikasi_token',
            'message' => 'Token '.(int) config('kredensial.token.digit', 6).' digit telah dikirim bot SiGAP ke chat Telegram Anda.',
            'tantangan' => [
                'id' => $tantangan->id,
                'tujuan' => $tantangan->tujuan,
                'kedaluwarsa_pada' => $tantangan->kedaluwarsa_pada->toIso8601String(),
                'kirim_ulang_tersedia_pada' => $sisaKirimUlang > 0 ? $tantangan->terkirim_pada->copy()->addSeconds($jeda)->toIso8601String() : null,
                'sisa_kirim_ulang' => $sisaKirimUlang,
                'maks_percobaan' => (int) config('kredensial.token.maks_percobaan', 5),
            ],
        ], 202);
    }
}
