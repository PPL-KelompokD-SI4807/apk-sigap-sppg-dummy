<?php

namespace App\Services\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Jalur cookie akses web R-04 (ADR `docs/decisions/2026-11-09-akses-web.md` Keputusan 1-3, A1, A3).
 *
 * - Token sesi web (ability `klien:web`) hanya dibawa cookie `__Host-sigap_sesi` (HttpOnly, Secure,
 *   SameSite=Strict, Path=/, tanpa Domain); Max-Age mengikuti expires_at token. Nama tanpa prefiks
 *   (dan tanpa Secure) hanya untuk APP_ENV=local + config('kredensial.web.cookie_lokal_http').
 * - Pemilihan jalur (tokenDariRequest, dipasang di Sanctum::getAccessTokenFromRequestUsing): ada header
 *   Authorization -> HANYA Bearer; selain itu cookie, tetapi hanya bila SesiWebCookie berjalan di rute itu
 *   (rute publik/pra-login tidak pernah menerima cookie) DAN permintaan membawa `X-Sigap-Klien: web` (semua
 *   metode). Penolakan silang: SesiService::validasiToken, lalu PastikanJalurSesi (fail closed).
 * - CSRF metode pengubah jalur cookie: Origin persis = origin config('app.url'), `X-Sigap-Klien: web`, dan
 *   `X-CSRF-Token` = HMAC-SHA256(kunci turunan APP_KEY, sha256(nilai cookie sesi)) atau, sebelum login,
 *   HMAC(..., id pra-login). Token CSRF tidak pernah disimpan di cookie yang dapat dibaca JS.
 */
final class SesiWeb
{
    public const COOKIE_SESI = 'sigap_sesi';

    /** Dipakai R04-2 (id pra-login) dan R04-3 (browser tepercaya). */
    public const COOKIE_PRA = 'sigap_pra';

    public const COOKIE_TB = 'sigap_tb';

    public const HEADER_KLIEN = 'X-Sigap-Klien';

    public const HEADER_CSRF = 'X-CSRF-Token';

    public const NILAI_KLIEN = 'web';

    public const JALUR_COOKIE = 'cookie';

    public const JALUR_BEARER = 'bearer';

    /** Atribut request: jalur token yang dievaluasi guard Sanctum (diisi tokenDariRequest). */
    public const ATRIBUT_JALUR = 'sigap.jalur_token';

    /** Atribut request: nilai cookie sesi (diisi SesiWebCookie HANYA bila tanpa Authorization dan ada `X-Sigap-Klien: web`). */
    public const ATRIBUT_COOKIE = 'sigap.cookie_sesi';

    /** Atribut request: expires_at baru token web setelah catatPemakaian menulis (cookie diperbarui). */
    public const ATRIBUT_SAMPAI = 'sigap.sesi_web_sampai';

    /** Panjang wajar maksimum nilai cookie/Bearer (`id|secret` Sanctum + prefiks). */
    private const PANJANG_MAKS = 255;

    // ------------------------------------------------------------------ cookie

    /** Mode pengembangan lokal tanpa HTTPS (tidak pernah di luar APP_ENV=local). */
    public static function modeLokalHttp(): bool
    {
        return app()->environment('local') && config('kredensial.web.cookie_lokal_http') === true;
    }

    /** Nama cookie efektif untuk nama dasar (sigap_sesi|sigap_pra|sigap_tb). */
    public static function namaCookie(string $dasar): string
    {
        return self::modeLokalHttp() ? $dasar : '__Host-'.$dasar;
    }

    public static function namaCookieSesi(): string
    {
        return self::namaCookie(self::COOKIE_SESI);
    }

    /**
     * Cookie ber-atribut `__Host-`: Secure, HttpOnly, SameSite=Strict, Path=/, tanpa Domain. $sampai menjadi
     * Expires + Max-Age (cookie persisten); server tetap penentu masa berlaku.
     */
    public static function buatCookie(string $dasar, string $nilai, Carbon $sampai): Cookie
    {
        // Max-Age = sisa masa menurut jam aplikasi (now()); Symfony menghitung Max-Age dari time().
        $sisa = max(0, $sampai->getTimestamp() - now()->getTimestamp());

        return Cookie::create(
            self::namaCookie($dasar),
            $nilai,
            time() + $sisa,
            '/',
            null,
            ! self::modeLokalHttp(),
            true,
            false,
            Cookie::SAMESITE_STRICT,
        );
    }

    /** Hapus cookie (Max-Age=0) dengan atribut yang sama agar browser menerima penghapusan `__Host-`. */
    public static function lupakanCookie(string $dasar): Cookie
    {
        return Cookie::create(
            self::namaCookie($dasar),
            null,
            1,
            '/',
            null,
            ! self::modeLokalHttp(),
            true,
            false,
            Cookie::SAMESITE_STRICT,
        );
    }

    public static function cookieSesi(string $token, Carbon $sampai): Cookie
    {
        return self::buatCookie(self::COOKIE_SESI, $token, $sampai);
    }

    public static function lupakanCookieSesi(): Cookie
    {
        return self::lupakanCookie(self::COOKIE_SESI);
    }

    // ------------------------------------------------------------------ pemilihan jalur

    /**
     * Callback Sanctum::getAccessTokenFromRequestUsing. Ada header Authorization (apa pun isinya) -> Bearer
     * saja, cookie diabaikan; Bearer tidak sah tetap 401. Tanpa Authorization -> cookie bila SesiWebCookie
     * menerimanya di rute ini (termasuk syarat `X-Sigap-Klien: web`). Format Bearer diperiksa sama dengan Sanctum bawaan (Guard::isValidBearerToken).
     */
    public static function tokenDariRequest(Request $request): ?string
    {
        if (! $request->headers->has('Authorization')) {
            $cookie = $request->attributes->get(self::ATRIBUT_COOKIE);

            if (is_string($cookie) && $cookie !== '') {
                $request->attributes->set(self::ATRIBUT_JALUR, self::JALUR_COOKIE);

                return self::formatSah($cookie) ? $cookie : null;
            }
        }

        $request->attributes->set(self::ATRIBUT_JALUR, self::JALUR_BEARER);
        $bearer = $request->bearerToken();

        return $bearer !== null && self::formatSah($bearer) ? $bearer : null;
    }

    public static function jalurCookie(Request $request): bool
    {
        return $request->attributes->get(self::ATRIBUT_JALUR) === self::JALUR_COOKIE;
    }

    private static function formatSah(string $token): bool
    {
        if ($token === '' || strlen($token) > self::PANJANG_MAKS) {
            return false;
        }

        if (str_contains($token, '|')) {
            [$id, $rahasia] = explode('|', $token, 2);

            return ctype_digit($id) && $rahasia !== '';
        }

        return true;
    }

    // ------------------------------------------------------------------ CSRF + Origin

    /**
     * Origin tepercaya = skema://host[:port] dari config('app.url'), huruf kecil, tanpa path. Null bila
     * APP_URL tidak dapat diurai (semua metode pengubah jalur cookie ditolak: fail closed).
     */
    public static function originDiizinkan(): ?string
    {
        $bagian = parse_url((string) config('app.url'));

        if (! is_array($bagian) || ! isset($bagian['scheme'], $bagian['host'])) {
            return null;
        }

        $skema = strtolower($bagian['scheme']);

        if (! in_array($skema, ['http', 'https'], true)) {
            return null;
        }

        $port = isset($bagian['port']) && ! (($skema === 'https' && $bagian['port'] === 443) || ($skema === 'http' && $bagian['port'] === 80))
            ? ':'.$bagian['port']
            : '';

        return $skema.'://'.strtolower($bagian['host']).$port;
    }

    /** Kunci CSRF diturunkan dari APP_KEY (domain terpisah dari hashPerangkat dsb.). */
    private static function kunci(): string
    {
        return hash_hmac('sha256', 'sigap|csrf-web|v1', (string) config('app.key'), true);
    }

    /** Token CSRF terikat sesi web (nilai cookie sesi). Berganti bila sesi berganti (anti-fiksasi). */
    public static function csrfSesi(string $tokenSesi): string
    {
        return hash_hmac('sha256', 'sesi|'.hash('sha256', $tokenSesi), self::kunci());
    }

    /** Token CSRF sebelum login, terikat id pra-login (cookie `__Host-sigap_pra`, R04-2). */
    public static function csrfPraLogin(string $idPraLogin): string
    {
        return hash_hmac('sha256', 'pra|'.$idPraLogin, self::kunci());
    }

    /**
     * Periksa tiga syarat metode pengubah jalur cookie. Gagal -> 403 `alasan: csrf_ditolak` (pesan seragam,
     * tanpa menyebut syarat mana yang gagal).
     */
    public static function wajibCsrf(Request $request, string $harapan): void
    {
        $origin = $request->headers->get('Origin');
        $diizinkan = self::originDiizinkan();
        $klien = $request->headers->get(self::HEADER_KLIEN);
        $csrf = $request->headers->get(self::HEADER_CSRF);

        $sah = $diizinkan !== null
            && is_string($origin) && hash_equals($diizinkan, $origin)
            && $klien === self::NILAI_KLIEN
            && is_string($csrf) && hash_equals($harapan, $csrf);

        if (! $sah) {
            throw GalatAuth::respons(403, GalatAuth::CSRF_DITOLAK, 'Permintaan ditolak. Muat ulang halaman lalu coba lagi.');
        }
    }
}
