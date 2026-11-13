<?php

namespace App\Http\Middleware;

use App\Services\Auth\GalatAuth;
use App\Services\Auth\SesiWeb;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gerbang rute login web pra-login `api/v1/web/auth/*` (R04-2). Rute ini TIDAK memakai SesiWebCookie maupun
 * auth:sanctum: cookie sesi `__Host-sigap_sesi` tidak pernah dievaluasi di sini.
 *
 * - Semua metode WAJIB `X-Sigap-Klien: web` (memaksa preflight lintas origin; tanpa CORS -> gagal di browser).
 * - Metode pengubah WAJIB CSRF pra-login tiga lapis: cookie `__Host-sigap_pra` (dari GET web/auth/csrf) +
 *   `Origin` persis origin APP_URL + `X-CSRF-Token` = SesiWeb::csrfPraLogin(id pra-login). Tanpa cookie pra-login
 *   atau salah satu syarat gagal -> 403 `alasan: csrf_ditolak` (pesan sama dengan jalur cookie sesi).
 * - Id pra-login yang lolos diserahkan ke controller lewat atribut request (bukan dibaca ulang dari cookie).
 * - Semua respons: `Cache-Control: no-store, private` + `Vary: Cookie`.
 */
class PraLoginWeb
{
    public const ATRIBUT_PRA = 'sigap.pra_login_web';

    public function handle(Request $request, Closure $next): Response
    {
        try {
            if ($request->headers->get(SesiWeb::HEADER_KLIEN) !== SesiWeb::NILAI_KLIEN) {
                throw self::ditolak();
            }

            if (! $request->isMethodSafe() || ! in_array($request->getRealMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
                $pra = SesiWeb::idPraLoginDari($request) ?? throw self::ditolak();
                SesiWeb::wajibCsrf($request, SesiWeb::csrfPraLogin($pra));
                $request->attributes->set(self::ATRIBUT_PRA, $pra);
            }
        } catch (HttpResponseException $e) {
            throw new HttpResponseException(self::tanpaCache($e->getResponse()));
        }

        return self::tanpaCache($next($request));
    }

    private static function ditolak(): HttpResponseException
    {
        return GalatAuth::respons(403, GalatAuth::CSRF_DITOLAK, 'Permintaan ditolak. Muat ulang halaman lalu coba lagi.');
    }

    private static function tanpaCache(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->setVary('Cookie', false);

        return $response;
    }
}
