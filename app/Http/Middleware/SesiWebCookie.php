<?php

namespace App\Http\Middleware;

use App\Services\Auth\SesiWeb;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Jalur cookie web R-04: cookie `__Host-sigap_sesi` -> token, dipasang SEBELUM auth:sanctum hanya di grup
 * rute login penuh (rute publik/pra-login tidak pernah menerima cookie).
 *
 * - Ada header Authorization -> middleware ini tidak melakukan apa pun (jalur Bearer Mini App tidak tersentuh;
 *   cookie diabaikan, Bearer tidak sah tetap 401).
 * - Tanpa Authorization, jalur cookie HANYA aktif bila `X-Sigap-Klien: web` (semua metode, termasuk GET/HEAD/
 *   OPTIONS). Tanpa header itu cookie diabaikan dan TIDAK dihapus: Mini App satu origin yang belum/tidak lagi
 *   memasang Authorization (sebelum login senyap, setelah logout/401) tidak ikut memakai sesi web di browser
 *   yang sama dan tidak menghapusnya.
 * - Jalur cookie aktif: nilai cookie diserahkan ke guard Sanctum (SesiWeb::tokenDariRequest). Metode pengubah
 *   (selain GET/HEAD/OPTIONS) WAJIB lolos CSRF tiga lapis SEBELUM autentikasi (SesiWeb::wajibCsrf), sehingga
 *   permintaan palsu tidak memperpanjang sesi.
 * - Respons jalur cookie: selalu `Cache-Control: no-store, private` + `Vary: Cookie`. 401 -> cookie dihapus;
 *   masa sesi bergeser (SesiService::catatPemakaian menulis) -> cookie diterbitkan ulang dengan Max-Age baru;
 *   sesi ini dicabut oleh permintaan pengubah (logout, keluar semua, cabut sesi ini) -> cookie dihapus.
 * - Penolakan silang (token Mini App lewat cookie, token web lewat Bearer) diperiksa dua kali: di
 *   SesiService::validasiToken (saat guard memvalidasi) dan, fail closed, di PastikanJalurSesi sesudah
 *   auth:sanctum sebelum controller berjalan.
 */
class SesiWebCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->headers->has('Authorization')) {
            return $next($request);
        }

        $nilai = $request->cookies->get(SesiWeb::namaCookieSesi());

        if (! is_string($nilai) || $nilai === '' || $request->headers->get(SesiWeb::HEADER_KLIEN) !== SesiWeb::NILAI_KLIEN) {
            return $next($request);
        }

        $request->attributes->set(SesiWeb::ATRIBUT_COOKIE, $nilai);

        $pengubah = self::pengubah($request);

        if ($pengubah) {
            try {
                SesiWeb::wajibCsrf($request, SesiWeb::csrfSesi($nilai));
            } catch (HttpResponseException $e) {
                // 403 csrf_ditolak juga respons jalur cookie: tanpa cache.
                throw new HttpResponseException(self::tanpaCache($e->getResponse()));
            }
        }

        $response = self::tanpaCache($next($request));

        if ($response->getStatusCode() === 401) {
            return self::denganCookie($response, SesiWeb::lupakanCookieSesi());
        }

        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken || ! SesiWeb::jalurCookie($request)) {
            return $response;
        }

        if ($pengubah && ! PersonalAccessToken::query()->whereKey($token->getKey())->exists()) {
            return self::denganCookie($response, SesiWeb::lupakanCookieSesi());
        }

        $sampai = $request->attributes->get(SesiWeb::ATRIBUT_SAMPAI);

        return $sampai instanceof Carbon ? self::denganCookie($response, SesiWeb::cookieSesi($nilai, $sampai)) : $response;
    }

    /**
     * Metode pengubah = metode efektif ATAU metode asli bukan GET/HEAD/OPTIONS (POST + X-HTTP-Method-Override: GET
     * tetap wajib CSRF).
     */
    private static function pengubah(Request $request): bool
    {
        return ! $request->isMethodSafe() || ! in_array($request->getRealMethod(), ['GET', 'HEAD', 'OPTIONS'], true);
    }

    /** Semua respons jalur cookie bersifat per pengguna: jangan disimpan cache bersama, bedakan per Cookie. */
    private static function tanpaCache(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->setVary('Cookie', false);

        return $response;
    }

    private static function denganCookie(Response $response, Cookie $cookie): Response
    {
        $response->headers->setCookie($cookie);

        return self::tanpaCache($response);
    }
}
