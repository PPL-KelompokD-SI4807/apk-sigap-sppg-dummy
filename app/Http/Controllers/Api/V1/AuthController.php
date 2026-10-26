<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Auth\TelegramLoginRequest;
use App\Http\Resources\AuthUserResource;
use App\Services\Telegram\TelegramAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends ApiController
{
    /** POST /api/v1/auth/telegram — tukar initData dengan token Bearer. */
    public function telegram(TelegramLoginRequest $request, TelegramAuthService $auth): JsonResponse
    {
        $result = $auth->login(
            (string) $request->validated('init_data'),
            (string) ($request->validated('device') ?: 'miniapp'),
        );

        return response()->json([
            'token' => $result['token'],
            'token_type' => 'Bearer',
            'expires_at' => $result['expires_at']?->toIso8601String(),
            'user' => (new AuthUserResource($result['user']))->resolve($request),
        ]);
    }

    /** GET /api/v1/auth/me */
    public function me(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        return response()->json([
            'user' => (new AuthUserResource($request->user()))->resolve($request),
            'expires_at' => $token instanceof PersonalAccessToken ? $token->expires_at?->toIso8601String() : null,
        ]);
    }

    /** POST /api/v1/auth/logout — cabut token yang sedang dipakai. */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(['message' => 'Berhasil keluar.']);
    }
}
