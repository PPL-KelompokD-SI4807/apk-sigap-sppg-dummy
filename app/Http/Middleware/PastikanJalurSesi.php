<?php

namespace App\Http\Middleware;

use App\Services\Auth\SesiService;
use App\Services\Auth\SesiWeb;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pemeriksaan kedua penolakan silang R-04, SESUDAH auth:sanctum dan SEBELUM controller (fail closed).
 *
 * Pemeriksaan pertama (SesiService::validasiToken) membaca jalur dari request() global, sedangkan jalur dicatat
 * tokenDariRequest pada request milik guard. Middleware ini memeriksa ulang pada request pipeline yang sama
 * dengan yang diisi SesiWebCookie, sehingga perbedaan objek request tidak pernah membuat token diterima:
 * - jalur cookie, token bukan `klien:web` (mis. token Mini App lewat cookie) -> 401 (cookie dihapus oleh
 *   SesiWebCookie karena 401 pada jalur cookie);
 * - SesiWebCookie mengisi ATRIBUT_COOKIE tetapi jalur yang tercatat bukan cookie -> 401;
 * - token `klien:web` lewat jalur selain cookie (Bearer) -> 401.
 */
class PastikanJalurSesi
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $jalurCookie = SesiWeb::jalurCookie($request);
            $cookieDiterima = is_string($request->attributes->get(SesiWeb::ATRIBUT_COOKIE));

            if ($jalurCookie !== SesiService::tokenWeb($token) || $cookieDiterima !== $jalurCookie) {
                throw new AuthenticationException;
            }
        }

        return $next($request);
    }
}
