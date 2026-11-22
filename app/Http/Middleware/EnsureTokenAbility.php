<?php

namespace App\Http\Middleware;

use App\Services\Auth\GalatAuth;
use App\Services\Auth\SesiService;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wajibkan token Sanctum (personal access token) memegang ability tertentu secara EKSPLISIT
 * (R-03). Dipasang di routes/api.php sebagai EnsureTokenAbility::class.':<ability>'.
 *
 * - `auth:penuh`     : semua rute terautentikasi selain proses masuk (login penuh).
 * - `auth:pra-login` : hanya rute masuk/aktivasi/reset/token.
 *
 * Token wildcard ("*"), token transien (cookie SPA), dan token pra-login ditolak 403 di rute
 * login penuh. Peran/scope SPPG tetap diputuskan Policy, bukan ability token.
 */
class EnsureTokenAbility
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $token = $request->user()?->currentAccessToken();

        // Wildcard diperiksa pada token tersimpan (token tiruan Sanctum::actingAs di tes tidak tersimpan).
        $sah = $token instanceof PersonalAccessToken
            && $token->can($ability)
            && ! ($token->exists && in_array('*', (array) $token->abilities, true));

        if (! $sah) {
            throw $ability === SesiService::ABILITY_PRA_LOGIN
                ? GalatAuth::respons(403, GalatAuth::BUKAN_PRA_LOGIN, 'Endpoint ini hanya untuk proses masuk. Buka ulang Mini App.')
                : GalatAuth::respons(403, GalatAuth::PERLU_LOGIN_PENUH, 'Silakan masuk dengan username/no telepon dan password terlebih dahulu.');
        }

        return $next($request);
    }
}
