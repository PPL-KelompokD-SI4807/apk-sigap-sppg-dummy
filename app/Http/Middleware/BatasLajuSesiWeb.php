<?php

namespace App\Http\Middleware;

use App\Services\Auth\GalatAuth;
use App\Services\Auth\SesiWeb;
use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Batas laju jalur cookie web SEBELUM CSRF dan autentikasi (R04-4, issue #81). Dipasang PALING DEPAN di grup login
 * penuh (sebelum SesiWebCookie; urutan dijaga priority list di bootstrap/app.php), sehingga permintaan yang ditolak
 * 403 csrf_ditolak atau 401 tetap terhitung dan banjir permintaan berhenti sebelum query token ke basis data.
 *
 * - Hanya jalur cookie: tanpa header Authorization DAN `X-Sigap-Klien: web` (syarat yang sama dengan SesiWebCookie).
 *   Jalur Bearer (Mini App) langsung diteruskan tanpa dihitung; batasnya tetap `throttle:api` sesudah auth.
 * - Kunci per IP saja (pengguna belum diketahui sebelum autentikasi). IP = Request::ip(): REMOTE_ADDR, atau
 *   X-Forwarded-For hanya dari proxy tepercaya (config/trustedproxy.php). Tanpa pemutus arus global (tuas DoS).
 * - Dua bucket per menit: semua permintaan jalur cookie, dan kegagalan (401 atau 403 csrf_ditolak). Bucket
 *   kegagalan diperiksa sebelum permintaan diproses dan ditambah sesudah respons diketahui.
 * - Kena batas -> ThrottleRequestsException (429 seragam dari bootstrap/app.php + Retry-After), tanpa membedakan
 *   cookie sah/tidak sah/tidak ada. Dicatat ke log paling banyak sekali per IP per menit (sidik IP ber-HMAC).
 */
class BatasLajuSesiWeb
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->headers->has('Authorization') || $request->headers->get(SesiWeb::HEADER_KLIEN) !== SesiWeb::NILAI_KLIEN) {
            return $next($request);
        }

        $ip = (string) $request->ip();
        $kunciSemua = 'sesiweb:ip:'.$ip;
        $kunciGagal = 'sesiweb:gagal:ip:'.$ip;
        $maksSemua = self::batas('sesi_per_ip_per_menit', 300);
        $maksGagal = self::batas('sesi_gagal_per_ip_per_menit', 30);

        foreach ([$kunciGagal => $maksGagal, $kunciSemua => $maksSemua] as $kunci => $maks) {
            if (RateLimiter::tooManyAttempts($kunci, $maks)) {
                self::catat($ip, $kunci === $kunciGagal ? 'gagal' : 'semua');

                throw new ThrottleRequestsException('Too Many Attempts.', null, [
                    'Retry-After' => max(1, RateLimiter::availableIn($kunci)),
                    'Cache-Control' => 'no-store, private',
                ]);
            }
        }

        RateLimiter::hit($kunciSemua, 60);

        $response = $next($request);

        if (self::gagal($response)) {
            RateLimiter::hit($kunciGagal, 60);
        }

        return $response;
    }

    /** 401 (cookie tidak sah/kedaluwarsa/tidak ada) atau 403 csrf_ditolak. 403 RBAC biasa tidak dihitung. */
    private static function gagal(Response $response): bool
    {
        $status = $response->getStatusCode();

        if ($status === 401) {
            return true;
        }

        return $status === 403
            && $response instanceof JsonResponse
            && ($response->getData(true)['alasan'] ?? null) === GalatAuth::CSRF_DITOLAK;
    }

    private static function batas(string $kunci, int $bawaan): int
    {
        return max(1, (int) config('kredensial.web.throttle.'.$kunci, $bawaan));
    }

    private static function catat(string $ip, string $bucket): void
    {
        $sidik = substr(hash_hmac('sha256', 'batas-sesi-web|'.$ip, (string) config('app.key')), 0, 16);

        if (Cache::add('sesiweb:log:'.$sidik, 1, 60)) {
            Log::warning('auth.web: batas laju jalur cookie tercapai.', ['bucket' => $bucket, 'ip_sidik' => $sidik]);
        }
    }
}
