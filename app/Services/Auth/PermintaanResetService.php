<?php

namespace App\Services\Auth;

use App\Enums\Role;
use App\Enums\StatusPermintaanReset;
use App\Jobs\SendTelegramNotification;
use App\Models\PermintaanResetPassword;
use App\Models\Scopes\SppgScope;
use App\Models\User;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Permintaan reset password yang disetujui Admin (R-03, menggantikan reset mandiri via token bot).
 *
 * Alur: pemilik akun (token pra-login) meminta -> Admin dalam scope-nya (Policy) memeriksa identitas
 * di luar sistem lalu menyetujui/menolak -> pemilik menetapkan password baru dan token 6 digit dikirim
 * bot ke users.telegram_id dari DB -> token benar = password diganti, semua sesi dicabut.
 *
 * - Satu permintaan aktif (menunggu/disetujui, < 24 jam) per akun; akun terkunci tidak bisa meminta.
 * - Admin tidak pernah melihat/menetapkan password dan tidak bisa memutus permintaannya sendiri.
 * - Notifikasi bot tidak memuat password, token, atau no telepon.
 */
class PermintaanResetService
{
    public const BERLAKU_JAM = 24;

    /** Permintaan baru per akun per 24 jam (mencegah spam notifikasi ke Admin). */
    public const MAKS_PER_HARI = 3;

    /** Permintaan aktif, atau yang terakhir (untuk menampilkan status ditolak/kedaluwarsa/selesai). */
    public function terkini(User $user): ?PermintaanResetPassword
    {
        $query = fn () => PermintaanResetPassword::query()->withoutGlobalScope(SppgScope::class)->where('user_id', $user->getKey());

        return $query()->aktif()->latest()->first() ?? $query()->latest()->first();
    }

    /**
     * @return array{0: PermintaanResetPassword, 1: bool} [permintaan, baru dibuat]
     */
    public function minta(User $user): array
    {
        if (! $user->punyaPassword()) {
            throw GalatAuth::perluAktivasi();
        }

        if ($user->terkunci()) {
            AuditAuth::catat($user, AuditAuth::DITOLAK_TERKUNCI, ['proses' => 'permintaan_reset']);

            throw GalatAuth::terkunci($user->locked_until);
        }

        $hasil = DB::transaction(function () use ($user): array {
            // Kunci baris pengguna: dua permintaan bersamaan tidak membuat dua permintaan aktif.
            User::withoutGlobalScopes()->whereKey($user->getKey())->lockForUpdate()->first();

            $aktif = PermintaanResetPassword::query()->withoutGlobalScope(SppgScope::class)
                ->where('user_id', $user->getKey())->aktif()->latest()->first();

            if ($aktif !== null) {
                return [$aktif, false];
            }

            $kunci = 'permintaan-reset:'.$user->getKey();

            if (RateLimiter::tooManyAttempts($kunci, self::MAKS_PER_HARI)) {
                throw GalatAuth::respons(429, GalatAuth::TERLALU_SERING, 'Terlalu banyak permintaan reset hari ini. Hubungi Admin SPPG secara langsung.');
            }

            if ($user->isAdmin() && $this->pemutus($user)->isEmpty()) {
                throw GalatAuth::respons(409, GalatAuth::TIDAK_ADA_PEMUTUS, 'Tidak ada Admin lain yang dapat menyetujui. Minta pengelola server menjalankan sigap:reset-password-admin.');
            }

            RateLimiter::hit($kunci, self::BERLAKU_JAM * 3600);

            $permintaan = new PermintaanResetPassword;
            $permintaan->forceFill([
                'user_id' => $user->getKey(),
                'sppg_id' => $user->sppg_id,
                'status' => StatusPermintaanReset::Menunggu,
                'kedaluwarsa_pada' => now()->addHours(self::BERLAKU_JAM),
            ])->save();

            return [$permintaan, true];
        });

        if ($hasil[1]) {
            AuditAuth::catat($user, AuditAuth::RESET_DIMINTA, ['permintaan' => $hasil[0]->id]);
            $this->beritahuPemutus($hasil[0], $user);
        }

        return $hasil;
    }

    public function setujui(User $admin, PermintaanResetPassword $permintaan): PermintaanResetPassword
    {
        return $this->putuskan($admin, $permintaan, StatusPermintaanReset::Disetujui);
    }

    public function tolak(User $admin, PermintaanResetPassword $permintaan): PermintaanResetPassword
    {
        return $this->putuskan($admin, $permintaan, StatusPermintaanReset::Ditolak);
    }

    /** Permintaan disetujui & aktif milik $user (syarat menetapkan password baru), atau null. */
    public function disetujuiUntuk(User $user, bool $kunci = false): ?PermintaanResetPassword
    {
        return PermintaanResetPassword::query()->withoutGlobalScope(SppgScope::class)
            ->where('user_id', $user->getKey())
            ->where('status', StatusPermintaanReset::Disetujui->value)
            ->where('kedaluwarsa_pada', '>', now())
            ->when($kunci, fn ($q) => $q->lockForUpdate())
            ->latest()->first();
    }

    private function putuskan(User $admin, PermintaanResetPassword $permintaan, StatusPermintaanReset $keputusan): PermintaanResetPassword
    {
        $diputus = DB::transaction(function () use ($admin, $permintaan, $keputusan): PermintaanResetPassword {
            $segar = PermintaanResetPassword::query()->withoutGlobalScope(SppgScope::class)
                ->lockForUpdate()->findOrFail($permintaan->getKey());

            if ($segar->status !== StatusPermintaanReset::Menunggu || $segar->kedaluwarsa()) {
                throw GalatAuth::respons(409, GalatAuth::PERMINTAAN_TIDAK_AKTIF, 'Permintaan ini sudah diputuskan atau kedaluwarsa.', tambahan: ['status' => $segar->statusEfektif()]);
            }

            $segar->forceFill([
                'status' => $keputusan,
                'diputuskan_oleh' => $admin->getKey(),
                'diputuskan_pada' => now(),
            ])->save();

            return $segar;
        });

        $pemilik = User::query()->withoutGlobalScopes()->findOrFail($diputus->user_id);
        $disetujui = $keputusan === StatusPermintaanReset::Disetujui;
        AuditAuth::catat($pemilik, $disetujui ? AuditAuth::RESET_DISETUJUI : AuditAuth::RESET_DITOLAK, ['permintaan' => $diputus->id], $admin);

        $this->kirim($pemilik, $disetujui
            ? "Permintaan reset password SiGAP Anda <b>disetujui</b> Admin.\nBuka Mini App, pilih <b>Lupa password</b>, tetapkan password baru, lalu masukkan token yang dikirim bot. Berlaku sampai ".$diputus->kedaluwarsa_pada->timezone(config('app.timezone'))->format('d/m H:i').'.'
            : "Permintaan reset password SiGAP Anda <b>ditolak</b> Admin.\nBila Anda tidak memintanya, abaikan pesan ini. Bila perlu, hubungi Admin SPPG secara langsung.",
            'permintaan_reset_'.$keputusan->value);

        return $diputus;
    }

    /**
     * Admin aktif yang berhak memutus permintaan $pemohon (bukan dirinya sendiri).
     *
     * @return Collection<int, User>
     */
    private function pemutus(User $pemohon): Collection
    {
        return User::query()->withoutGlobalScopes()
            ->where('role', Role::Admin->value)->where('is_active', true)->whereNull('deleted_at')
            ->whereNotNull('telegram_id')->whereKeyNot($pemohon->getKey())
            ->get()
            ->filter(fn (User $admin) => $admin->can('update', $pemohon));
    }

    private function beritahuPemutus(PermintaanResetPassword $permintaan, User $pemohon): void
    {
        $sppg = $pemohon->sppg_id === null ? 'lintas SPPG' : (string) (DB::table('sppgs')->where('id', $pemohon->sppg_id)->value('nama') ?? 'SPPG');
        $html = sprintf(
            "Permintaan <b>reset password</b> dari <b>%s</b> (%s, %s).\nPastikan identitasnya langsung (telepon/tatap muka) sebelum menyetujui. Putuskan di Mini App: Pengguna > Permintaan reset.",
            TelegramBotService::escapeHtml($pemohon->name),
            TelegramBotService::escapeHtml($pemohon->role->label()),
            TelegramBotService::escapeHtml($sppg),
        );

        foreach ($this->pemutus($pemohon) as $admin) {
            $this->kirim($admin, $html, 'permintaan_reset_baru');
        }
    }

    private function kirim(User $penerima, string $html, string $jenis): void
    {
        if ($penerima->telegram_id === null) {
            return;
        }

        $bot = app(TelegramBotService::class);
        $markup = TelegramBotService::inlineKeyboard([TelegramBotService::webAppButton('Buka SiGAP', $bot->miniAppUrl())]);

        SendTelegramNotification::dispatch((int) $penerima->telegram_id, $html, $markup, $penerima->getKey(), $jenis);
    }
}
