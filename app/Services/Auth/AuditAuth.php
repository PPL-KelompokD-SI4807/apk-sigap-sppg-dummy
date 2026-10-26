<?php

namespace App\Services\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\App;

/**
 * Log audit kejadian login R-03 (append-only, tabel audit_logs, auditable = akun yang login).
 *
 * Konteks TIDAK PERNAH memuat password, token 6 digit, token Bearer, no telepon, atau isi
 * identitas yang diketik; hanya metode ("username"/"telepon"), tujuan, sisa percobaan, dan
 * sidik perangkat pendek (8 karakter awal HMAC device_id).
 */
final class AuditAuth
{
    public const PRA_LOGIN = 'auth.pra_login';

    public const MASUK_BERHASIL = 'auth.masuk_berhasil';

    public const MASUK_GAGAL = 'auth.masuk_gagal';

    public const IDENTITAS_TIDAK_COCOK = 'auth.identitas_tidak_cocok';

    public const AKUN_TERKUNCI = 'auth.akun_terkunci';

    public const DITOLAK_TERKUNCI = 'auth.ditolak_terkunci';

    public const TOKEN_DIKIRIM = 'auth.token_dikirim';

    public const TOKEN_GAGAL_KIRIM = 'auth.token_gagal_kirim';

    public const TOKEN_SALAH = 'auth.token_salah';

    public const TOKEN_HANGUS = 'auth.token_hangus';

    public const TOKEN_DITOLAK = 'auth.token_ditolak';

    public const AKTIVASI_BERHASIL = 'auth.aktivasi_berhasil';

    public const LOGOUT = 'auth.logout';

    public const KELUAR_SEMUA = 'auth.keluar_semua';

    public const PASSWORD_DIRESET_ADMIN = 'auth.password_direset_admin';

    public const SESI_DICABUT_ADMIN = 'auth.sesi_dicabut_admin';

    public const PASSWORD_DIUBAH = 'auth.password_diubah';

    public const RESET_DIMINTA = 'auth.reset_diminta';

    public const RESET_DISETUJUI = 'auth.reset_disetujui';

    public const RESET_DITOLAK = 'auth.reset_ditolak';

    public const RESET_PASSWORD_BERHASIL = 'auth.reset_password_berhasil';

    public const UBAH_PASSWORD_GAGAL = 'auth.ubah_password_gagal';

    /** Satu sesi dicabut pemiliknya dari daftar sesi. */
    public const SESI_DICABUT = 'auth.sesi_dicabut';

    /** Aktivasi: no telepon tidak disimpan karena sudah dipakai akun lain (tanpa nomornya). */
    public const TELEPON_TIDAK_DISIMPAN = 'auth.telepon_tidak_disimpan';

    /** Token penuh dikirim bersama initData akun Telegram lain lalu dicabut. */
    public const SESI_DICABUT_AKUN_BERBEDA = 'auth.sesi_dicabut_akun_berbeda';

    /** @param array<string, mixed> $konteks */
    public static function catat(User $subjek, string $event, array $konteks = [], ?User $aktor = null): void
    {
        $request = App::runningInConsole() && ! App::runningUnitTests() ? null : request();

        $log = new AuditLog;
        $log->forceFill([
            'sppg_id' => $subjek->sppg_id,
            'user_id' => ($aktor ?? $subjek)->getKey(),
            'event' => $event,
            'auditable_type' => $subjek->getMorphClass(),
            'auditable_id' => $subjek->getKey(),
            'old_values' => null,
            'new_values' => $konteks === [] ? null : $konteks,
            'ip' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 512) : null,
            'sumber' => AuditLog::currentSource() ?? ($request ? 'web' : 'sistem'),
        ]);
        $log->save();
    }

    /** Sidik perangkat pendek untuk audit (bukan device_id mentah). */
    public static function sidik(?string $deviceHash): ?string
    {
        return $deviceHash === null ? null : substr($deviceHash, 0, 8);
    }
}
