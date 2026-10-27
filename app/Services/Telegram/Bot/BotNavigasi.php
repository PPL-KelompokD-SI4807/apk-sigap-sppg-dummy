<?php

namespace App\Services\Telegram\Bot;

use App\Enums\Role;
use App\Models\User;
use App\Services\Ai\ActingUser;
use App\Services\Auth\SesiService;
use App\Services\Telegram\Bot\Layar\KonteksLayar;
use App\Services\Telegram\Bot\Layar\LayarBaca;
use App\Support\SppgAktif;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Pembuka layar baca Bot (R-02 v2) untuk callback `n1` dan perintah pintasan. Hak dihitung ULANG di sini
 * pada setiap pemanggilan; token/perintah hanya menunjuk layar dan SPPG yang diminta.
 *
 * Urutan (pemanggil sudah memuat ulang pengguna dari basis data):
 *   a. pengguna aktif + SesiService::punyaSesiPenuh (diperiksa lagi walau pemanggil sudah memeriksa);
 *   b. layar terdaftar;
 *   c. hak RBAC peran SAAT INI dari config/rbac.php sesuai LayarBaca::hak() (bukan dari layar);
 *   d. SPPG efektif: peran satu-SPPG = users.sppg_id (SPPG lain di token -> ditolak); investor = pivot
 *      investor_sppg (SppgAktif::untuk); admin = SPPG ada & belum dihapus (SppgAktif::untukBacaAdmin, baca-saja);
 *      layar data tanpa SPPG efektif tidak pernah dirender;
 *   e. jeda per pengguna per layar (layar berat);
 *   f. di dalam ActingUser + konteks SPPG: LayarBaca::izinkan (Policy), lalu render.
 */
class BotNavigasi
{
    public function __construct(
        private readonly SesiService $sesi,
        private readonly BotLayarRegistry $registry,
    ) {}

    /** Instance scoped (per permintaan/job) diambil saat dipakai, bukan disimpan. */
    private function sppgAktif(): SppgAktif
    {
        return app(SppgAktif::class);
    }

    public function buka(User $user, string $layarId, ?int $sppgDiminta, KonteksLayar $konteks): BotNavHasil
    {
        $hasil = $this->periksaDanRender($user, $layarId, $sppgDiminta, $konteks);

        Log::info('bot.nav', [
            'layar' => $layarId,
            'hasil' => $hasil->status,
            'user_id' => (int) $user->getKey(),
            'sppg_id' => $sppgDiminta,
        ]);

        return $hasil;
    }

    private function periksaDanRender(User $user, string $layarId, ?int $sppgDiminta, KonteksLayar $konteks): BotNavHasil
    {
        // a. Sesi penuh.
        if (! $user->canAuthenticate() || ! $this->sesi->punyaSesiPenuh($user)) {
            return BotNavHasil::gagal(BotNavHasil::TANPA_SESI);
        }

        // b. Layar (id dapat berakhiran 2-4 = halaman ke-2..4 dari layar dasar).
        [$dasar, $halaman] = BotLayarRegistry::pecahId($layarId);
        $layar = $this->registry->cari($dasar);
        $konteks = $halaman > 0 ? $konteks->denganHalaman($halaman) : $konteks;

        if ($layar === null) {
            return BotNavHasil::gagal(BotNavHasil::TIDAK_TERSEDIA);
        }

        // c. RBAC peran saat ini.
        if (! BotLayarRegistry::hakRbac($user, $layar->modul(), $layar->hak())) {
            return BotNavHasil::gagal(BotNavHasil::TIDAK_BERHAK);
        }

        // d. SPPG efektif.
        $perluSppg = $layar->hak() !== LayarBaca::HAK_SESI || $layar->butuhSppg();

        if ($user->isAdmin()) {
            $sppgId = $sppgDiminta;
            $bisaMemilih = true;
        } elseif ($user->role === Role::Investor) {
            $this->sppgAktif()->segarkanMilik($user);
            $milik = $this->sppgAktif()->milik($user);
            $sppgId = $sppgDiminta ?? (count($milik) === 1 ? $milik[0] : null);
            $bisaMemilih = count($milik) > 1;
        } else {
            $sendiri = $user->sppg_id === null ? null : (int) $user->sppg_id;

            // Peran satu-SPPG tidak pernah berpindah SPPG lewat token.
            if ($sppgDiminta !== null && $sppgDiminta !== $sendiri) {
                return BotNavHasil::gagal(BotNavHasil::TIDAK_BERHAK);
            }

            $sppgId = $sendiri;
            $bisaMemilih = false;
        }

        if ($perluSppg && $sppgId === null) {
            return BotNavHasil::gagal($bisaMemilih ? BotNavHasil::PILIH_SPPG : BotNavHasil::TIDAK_BERHAK);
        }

        // e. Jeda layar berat.
        $jeda = $layar->jedaDetik();

        if ($jeda > 0 && ! RateLimiter::attempt('tg:nav:'.(int) $user->getKey().':'.$layar->id(), 1, fn () => true, $jeda)) {
            return BotNavHasil::gagal(BotNavHasil::TERLALU_CEPAT);
        }

        // f. Policy + render dalam konteks pengguna dan SPPG.
        $jalankan = function () use ($user, $layar, $sppgId, $konteks): BotNavHasil {
            if (! $layar->izinkan($user, $sppgId)) {
                return BotNavHasil::gagal(BotNavHasil::TIDAK_BERHAK);
            }

            return BotNavHasil::ok($layar->render($user, $sppgId, $konteks));
        };

        try {
            return ActingUser::run($user, fn (): BotNavHasil => match (true) {
                $sppgId === null => $jalankan(),
                $user->isAdmin() => $this->sppgAktif()->untukBacaAdmin($user, $sppgId, $jalankan),
                default => $this->sppgAktif()->untuk($user, $sppgId, $jalankan),
            });
        } catch (AuthorizationException) {
            return BotNavHasil::gagal(BotNavHasil::TIDAK_BERHAK);
        }
    }
}
