<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Menolak token milik pengguna yang dinonaktifkan setelah login (dan mencabut token itu).
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && method_exists($user, 'canAuthenticate') && ! $user->canAuthenticate()) {
            $token = $user->currentAccessToken();

            if ($token instanceof PersonalAccessToken) {
                $token->delete();
            }

            throw new AccessDeniedHttpException('Akun Anda nonaktif. Hubungi Admin SPPG.');
        }

        return $next($request);
    }
}
