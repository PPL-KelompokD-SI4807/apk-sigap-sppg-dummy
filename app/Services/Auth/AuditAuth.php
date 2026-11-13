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

    /** Masa sesi login penuh diperpanjang (sliding); dicatat sekali per token per hari kalender. */
    public const SESI_DIPERPANJANG = 'auth.sesi_diperpanjang';

    /** Sesi tertua dicabut karena login baru melebihi config('kredensial.sesi.maks_per_pengguna'). */
    public const SESI_DICABUT_BATAS = 'auth.sesi_dicabut_batas';

    /** Kepercayaan perangkat tertua dicabut karena perangkat tepercaya melebihi config('kredensial.sesi.maks_per_pengguna'). */
    public const PERANGKAT_DICABUT_BATAS = 'auth.perangkat_dicabut_batas';

    /** Semua sesi dicabut otomatis karena hak akses berubah, akun dihapus, atau akun dipulihkan. */
    public const SESI_DICABUT_OTOMATIS = 'auth.sesi_dicabut_otomatis';

    // ---- Login web R04-2 (ADR 2026-11-09 Keputusan 7). Tidak pernah memuat sandi, kode, token, cookie, id
    // pra-login, atau identitas mentah. Akun tak dikenal tidak punya subjek audit: hanya log aplikasi ber-hash.

    /** Langkah 1 web (identitas + sandi) untuk akun yang dikenal; `hasil` = alasan internal (tidak dibalas ke klien). */
    public const WEB_MASUK_LANGKAH1 = 'auth.web.masuk_langkah1';

    public const WEB_KODE_DIKIRIM = 'auth.web.kode_dikirim';

    public const WEB_KODE_GAGAL_KIRIM = 'auth.web.kode_gagal_kirim';

    /** Kode web salah/hangus/kedaluwarsa/terkunci (`alasan`). */
    public const WEB_KODE_GAGAL = 'auth.web.kode_gagal';

    public const WEB_MASUK_BERHASIL = 'auth.web.masuk_berhasil';

    /** Kanal web dikunci (Mini App tidak terpengaruh). */
    public const WEB_TERKUNCI = 'auth.web.terkunci';

    /** Percobaan web ditolak karena kanal web sedang terkunci. */
    public const WEB_DITOLAK_TERKUNCI = 'auth.web.ditolak_terkunci';

    /** Tombol bot "Bukan saya" ditekan pemilik akun Telegram. */
    public const WEB_BUKAN_SAYA = 'auth.web.bukan_saya';

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
