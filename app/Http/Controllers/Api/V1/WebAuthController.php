<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Middleware\PraLoginWeb;
use App\Http\Requests\Auth\Web\KirimUlangKodeWebRequest;
use App\Http\Requests\Auth\Web\KodeWebRequest;
use App\Http\Requests\Auth\Web\MasukWebRequest;
use App\Http\Resources\AuthUserResource;
use App\Models\User;
use App\Services\Auth\BrowserTepercayaService;
use App\Services\Auth\MasukWebService;
use App\Services\Auth\SesiService;
use App\Services\Auth\SesiWeb;
use App\Support\LabelBrowser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Login web R04-2 (ADR 2026-11-09 Keputusan 4): `api/v1/web/auth/*`, tanpa SesiWebCookie/auth:sanctum, digerbangi
 * PraLoginWeb (`X-Sigap-Klien: web` + CSRF pra-login untuk metode pengubah).
 *
 * Token sesi TIDAK PERNAH ada di badan JSON (issue #76): ia hanya keluar sebagai Set-Cookie `__Host-sigap_sesi`.
 */
class WebAuthController extends ApiController
{
    public const PESAN_KODE_DIKIRIM = 'Bila data benar, kode telah dikirim ke Telegram Anda. Tidak menerima kode dalam 1 menit? Periksa kembali sandi.';

    public function __construct(private readonly MasukWebService $masuk) {}

    /** GET /api/v1/web/auth/csrf — id pra-login baru (cookie `__Host-sigap_pra`) + token CSRF pra-login. */
    public function csrf(): JsonResponse
    {
        $id = SesiWeb::idPraLoginBaru();

        return response()->json([
            'csrf' => SesiWeb::csrfPraLogin($id),
            'berlaku_sampai' => now()->addMinutes(SesiWeb::umurPraMenit())->toIso8601String(),
        ])->withCookie(SesiWeb::cookiePra($id));
    }

    /**
     * POST /api/v1/web/auth/masuk — 200 `langkah: kode` (seragam, anti-enumerasi). Browser tepercaya (R04-3) yang
     * sah untuk akun ini + sandi benar: 200 `langkah: selesai` + Set-Cookie sesi (kode dilewati); sandi salah pada
     * browser tepercaya sah: 422 `alasan: kredensial_salah`. Cookie `__Host-sigap_tb` yang tidak berlaku lagi dihapus.
     */
    public function masuk(MasukWebRequest $request): JsonResponse
    {
        $cookieTb = $request->cookies->get(BrowserTepercayaService::namaCookie());
        $cookieLama = $request->cookies->get(SesiWeb::namaCookieSesi());

        $hasil = $this->masuk->masuk(
            (string) $request->validated('identitas'),
            (string) $request->validated('password'),
            $this->praLogin($request),
            LabelBrowser::dari($request->userAgent()),
            is_string($cookieTb) ? $cookieTb : null,
            is_string($cookieLama) ? $cookieLama : null,
        );

        $respons = $hasil['langkah'] === 'selesai'
            ? $this->selesai($request, $hasil['sesi'], ['dipakai' => true, 'diingat' => true, 'berlaku_sampai' => $hasil['browser_sampai']->toIso8601String()])
            : $this->tantangan($hasil['tantangan']);

        return $hasil['lupakan_tb'] ? $respons->withCookie(BrowserTepercayaService::lupakanCookie()) : $respons;
    }

    /** POST /api/v1/web/auth/kode/kirim-ulang — respons seragam (tantangan tiruan pun 200). */
    public function kirimUlang(KirimUlangKodeWebRequest $request): JsonResponse
    {
        $tantangan = $this->masuk->kirimUlang(
            (string) $request->validated('tantangan_id'),
            $this->praLogin($request),
            LabelBrowser::dari($request->userAgent()),
        );

        return $this->tantangan($tantangan);
    }

    /**
     * POST /api/v1/web/auth/kode — kode benar: sesi web lewat Set-Cookie `__Host-sigap_sesi`, cookie pra-login
     * dihapus, CSRF baru terikat sesi (anti-fiksasi). Gagal apa pun: 422 `alasan: kode_tidak_valid`.
     */
    public function kode(KodeWebRequest $request): JsonResponse
    {
        $cookieLama = $request->cookies->get(SesiWeb::namaCookieSesi());
        $cookieTb = $request->cookies->get(BrowserTepercayaService::namaCookie());

        $sesi = $this->masuk->verifikasi(
            (string) $request->validated('tantangan_id'),
            (string) $request->validated('kode'),
            $this->praLogin($request),
            LabelBrowser::dari($request->userAgent()),
            is_string($cookieLama) ? $cookieLama : null,
            $request->boolean('ingat_browser'),
            is_string($cookieTb) ? $cookieTb : null,
        );

        $respons = $this->selesai($request, $sesi, [
            'dipakai' => false,
            'diingat' => $sesi['ingat_browser'] === 'diingat',
            'berlaku_sampai' => $sesi['browser_sampai']?->toIso8601String(),
        ] + ($sesi['ingat_browser'] === 'diabaikan_admin' ? ['alasan' => 'admin_selalu_kode'] : []));

        return $sesi['browser_cookie'] instanceof Cookie ? $respons->withCookie($sesi['browser_cookie']) : $respons;
    }

    /**
     * Respons login web selesai (jalur kode atau browser tepercaya). Token sesi hanya di Set-Cookie.
     *
     * @param  array{cookie: Cookie, csrf: string, user: User, expires_at: Carbon, sesi_id: int}  $sesi
     * @param  array<string, mixed>  $browser
     */
    private function selesai(Request $request, array $sesi, array $browser): JsonResponse
    {
        return response()->json([
            'langkah' => 'selesai',
            'jenis_sesi' => SesiService::JENIS_WEB,
            'expires_at' => $sesi['expires_at']->toIso8601String(),
            'csrf' => $sesi['csrf'],
            'browser_tepercaya' => $browser,
            'user' => (new AuthUserResource($sesi['user']))->resolve($request),
        ])->withCookie($sesi['cookie'])->withCookie(SesiWeb::lupakanCookiePra());
    }

    private function praLogin(Request $request): string
    {
        /** @var string $pra dijamin PraLoginWeb untuk metode pengubah */
        $pra = $request->attributes->get(PraLoginWeb::ATRIBUT_PRA);

        return $pra;
    }

    /** @param array{id: string, kedaluwarsa_pada: Carbon, kirim_ulang_tersedia_pada: Carbon} $tantangan */
    private function tantangan(array $tantangan): JsonResponse
    {
        return response()->json([
            'langkah' => 'kode',
            'message' => self::PESAN_KODE_DIKIRIM,
            'tantangan' => [
                'id' => $tantangan['id'],
                'kedaluwarsa_pada' => $tantangan['kedaluwarsa_pada']->toIso8601String(),
                'kirim_ulang_tersedia_pada' => $tantangan['kirim_ulang_tersedia_pada']->toIso8601String(),
                'maks_kirim_ulang' => (int) config('kredensial.token.maks_kirim_ulang', 3),
                'maks_percobaan' => (int) config('kredensial.token.maks_percobaan', 5),
            ],
        ]);
    }
}
