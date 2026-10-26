<?php

namespace App\Http\Middleware;

use App\Services\Auth\GalatAuth;
use App\Support\SppgAktif;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validasi SPPG aktif (header X-SPPG-Id) di SETIAP permintaan login penuh (R-06a). Dipasang di
 * routes/api.php dan diprioritaskan (bootstrap/app.php) SETELAH `active` + EnsureTokenAbility
 * (token pra-login tidak pernah sampai ke sini) dan SEBELUM SubstituteBindings agar route
 * model binding ber-SppgScope sudah memakai SPPG aktif yang tervalidasi.
 *
 * - Header berisi SPPG yang tidak boleh diakses (investor: bukan miliknya; peran lain: bukan
 *   SPPG-nya; format salah) -> 403 alasan `sppg_aktif_ditolak` (juga pada rute pengecualian).
 * - Investor tanpa header dengan >1 SPPG -> 409 `sppg_aktif_belum_dipilih`; tanpa SPPG -> 403
 *   `tanpa_sppg`. Kecuali rute yang tidak butuh SPPG (profil/sesi/logout, daftar SPPG saya).
 * - Admin: header diabaikan (perilaku tidak berubah). Akun nonaktif diteruskan ke middleware
 *   `active` yang menolak dan mencabut tokennya.
 */
class ResolveSppgAktif
{
    /** Rute yang tetap dilayani walau investor belum memilih SPPG aktif. */
    public const TANPA_SPPG_AKTIF = ['api.v1.auth.*', 'api.v1.sppg-saya'];

    public function __construct(private readonly SppgAktif $sppgAktif) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->sppgAktif->reset();
        $user = $request->user();

        if ($user === null || ! $user->canAuthenticate() || $user->isAdmin()) {
            return $next($request);
        }

        $alasan = $this->sppgAktif->resolve($user)['alasan'];

        if ($alasan === SppgAktif::DITOLAK) {
            // R06a-S-08: upaya menebak ID SPPG terpantau. Header dipotong 20 karakter, hanya ASCII cetak.
            Log::notice('sppg_aktif.ditolak', [
                'user_id' => $user->getKey(),
                'header' => self::potongHeader($request->headers->get(SppgAktif::HEADER)),
            ]);

            throw GalatAuth::respons(403, SppgAktif::DITOLAK, 'SPPG yang dipilih tidak dapat Anda akses. Pilih ulang SPPG aktif.', tambahan: ['header' => SppgAktif::HEADER]);
        }

        if ($alasan !== null && ! $request->routeIs(...self::TANPA_SPPG_AKTIF)) {
            throw $alasan === SppgAktif::BELUM_DIPILIH
                ? GalatAuth::respons(409, SppgAktif::BELUM_DIPILIH, 'Pilih SPPG aktif terlebih dahulu.', tambahan: ['header' => SppgAktif::HEADER, 'langkah' => 'pilih_sppg'])
                : GalatAuth::respons(403, SppgAktif::TANPA_SPPG, 'Akun Anda belum terhubung dengan SPPG mana pun. Hubungi Admin.');
        }

        return $next($request);
    }

    /** Nilai header untuk log: maks. 20 karakter, karakter non-ASCII-cetak diganti "?" (cegah injeksi log). */
    public static function potongHeader(?string $nilai): string
    {
        return (string) preg_replace('/[^\x20-\x7E]/', '?', mb_substr((string) $nilai, 0, 20));
    }
}
