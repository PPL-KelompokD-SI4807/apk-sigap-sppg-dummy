<?php

namespace App\Services\Auth;

use App\Models\TantanganToken;
use App\Models\User;
use App\Support\Telepon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Lapis 2 login R-03: username ATAU no telepon + password, token 6 digit dari bot untuk aktivasi
 * dan perangkat baru.
 *
 * Aturan keamanan inti:
 * - $user SELALU pemilik token pra-login (= akun Telegram dari initData). Identitas yang diketik
 *   hanya dicocokkan dengan akun itu; identitas milik akun lain ditolak dengan pesan yang sama
 *   seperti password salah (tidak ada oracle akun) dan dicatat di audit.
 * - Password selalu diperiksa dengan Hash::check (juga saat identitas tidak cocok) agar waktu
 *   respons tidak membocorkan apa pun.
 * - Penguncian sementara per akun setelah salah berulang, atomik terhadap request paralel
 *   (reservasiPercobaan). Identitas wajib milik akun Telegram yang sama.
 * - Token pra-login dari bypass dev tidak pernah ditukar menjadi login penuh.
 * - Tidak ada reset password mandiri (pemegang Telegram tidak boleh melewati lapis password,
 *   termasuk saat akun terkunci). Reset hanya oleh Admin dalam scope-nya (Policy) atau
 *   perintah server sigap:reset-password-admin; pengguna lalu mengatur ulang lewat aktivasi.
 */
class KredensialService
{
    public function __construct(
        private readonly SesiService $sesi,
        private readonly TokenSekaliPakaiService $token,
    ) {}

    /**
     * @return array{langkah: string, token?: string, user?: User, tantangan?: TantanganToken}
     */
    public function masuk(User $user, string $identitas, string $password, string $deviceHash, ?string $label, PersonalAccessToken $praLogin): array
    {
        $this->tolakDev($user, $praLogin);

        if (! $user->punyaPassword()) {
            throw GalatAuth::perluAktivasi();
        }

        $this->reservasiPercobaan($user, $deviceHash);

        $cocok = $this->identitasCocok($user, $identitas);
        $passwordBenar = Hash::check($password, (string) $user->password);

        if (! $cocok || ! $passwordBenar) {
            $this->catatGagal($user, $identitas, $cocok, $deviceHash);
        }

        if (Hash::needsRehash((string) $user->password)) {
            // Tanpa event: rehash bukan perubahan password (tidak boleh mencabut sesi).
            User::withoutGlobalScopes()->whereKey($user->getKey())->update(['password' => Hash::make($password)]);
        }

        User::withoutGlobalScopes()->whereKey($user->getKey())->update(['failed_login_attempts' => 0, 'locked_until' => null]);

        if ($this->sesi->perangkatAktif($user, $deviceHash) !== null) {
            $hasil = $this->sesi->terbitkanPenuh($user, $deviceHash, $label, $praLogin);
            AuditAuth::catat($user, AuditAuth::MASUK_BERHASIL, ['metode' => $this->metode($identitas), 'perangkat' => AuditAuth::sidik($deviceHash), 'perangkat_tepercaya' => true]);

            return ['langkah' => 'selesai'] + $hasil;
        }

        $tantangan = $this->token->buat($user, TantanganToken::TUJUAN_PERANGKAT_BARU, $deviceHash, [
            'label' => $label,
            'metode' => $this->metode($identitas),
        ]);

        return ['langkah' => 'verifikasi_token', 'tantangan' => $tantangan];
    }

    /**
     * Aktivasi / atur password pertama kali (undangan, sigap:buat-admin, atau setelah reset Admin).
     *
     * @param  array{username?: string, phone?: string|null, password: string}  $data
     */
    public function mulaiAktivasi(User $user, array $data, string $deviceHash, ?string $label, PersonalAccessToken $praLogin): TantanganToken
    {
        $this->tolakDev($user, $praLogin);

        if ($user->punyaPassword()) {
            throw GalatAuth::sudahAktivasi();
        }

        $payload = ['password_hash' => Hash::make($data['password']), 'label' => $label];

        if (isset($data['username'])) {
            $payload['username'] = $data['username'];
        }

        if (array_key_exists('phone', $data)) {
            $payload['phone'] = $data['phone'] === null ? null : Telepon::normalisasi($data['phone']);
        }

        // Ketersediaan username diperiksa setelah jatah tantangan terpakai: pesan seragam dan
        // maks config('kredensial.token.maks_tantangan') kali per jendela (bukan oracle akun murah).
        return $this->token->buat($user, TantanganToken::TUJUAN_AKTIVASI, $deviceHash, $payload,
            fn () => $this->usernameDipakaiAkunLain($user, $payload['username'] ?? null) ? throw self::usernameTidakTersedia() : null);
    }

    public const USERNAME_TIDAK_TERSEDIA = 'Username tidak tersedia. Pilih username lain.';

    public static function usernameTidakTersedia(): ValidationException
    {
        return ValidationException::withMessages(['username' => [self::USERNAME_TIDAK_TERSEDIA]]);
    }

    private function usernameDipakaiAkunLain(User $user, ?string $username): bool
    {
        return $username !== null
            && DB::table('users')->where('username', $username)->where('id', '!=', $user->getKey())->exists();
    }

    /** No telepon milik akun lain (termasuk terhapus lunak, sesuai indeks unik). */
    private function teleponDipakaiAkunLain(User $user, ?string $phone): bool
    {
        return $phone !== null
            && DB::table('users')->where('phone', $phone)->where('id', '!=', $user->getKey())->exists();
    }

    /**
     * Konfirmasi token 6 digit lalu selesaikan tujuan tantangan dan terbitkan login penuh.
     *
     * @return array{langkah: string, token: string, user: User, tujuan: string}
     */
    public function selesaikan(User $user, string $tantanganId, string $kode, string $deviceHash, PersonalAccessToken $praLogin): array
    {
        $this->tolakDev($user, $praLogin);

        $hasil = $this->token->verifikasi($user, $tantanganId, $kode, $deviceHash, function (TantanganToken $tantangan) use ($user, $deviceHash, $praLogin): array {
            $payload = (array) $tantangan->payload;

            if ($tantangan->tujuan === TantanganToken::TUJUAN_AKTIVASI) {
                $this->terapkanAktivasi($user, $payload);
            }

            $label = isset($payload['label']) && is_string($payload['label']) ? $payload['label'] : null;

            return ['tujuan' => $tantangan->tujuan, 'metode' => $payload['metode'] ?? null]
                + $this->sesi->terbitkanPenuh($user->refresh(), $deviceHash, $label, $praLogin);
        });

        $event = $hasil['tujuan'] === TantanganToken::TUJUAN_AKTIVASI ? AuditAuth::AKTIVASI_BERHASIL : AuditAuth::MASUK_BERHASIL;
        AuditAuth::catat($user, $event, array_filter([
            'metode' => $hasil['metode'],
            'perangkat' => AuditAuth::sidik($deviceHash),
            'perangkat_tepercaya' => false,
        ], fn ($v) => $v !== null));

        return ['langkah' => 'selesai', 'token' => $hasil['token'], 'user' => $hasil['user'], 'tujuan' => $hasil['tujuan']];
    }

    /**
     * Reset password oleh Admin ($aktor) atau perintah server ($aktor null): password dikosongkan
     * (tidak pernah dilihat/ditentukan siapa pun selain pemilik), penguncian dibuka, semua sesi
     * dicabut; pengguna wajib atur password baru (+ token bot) saat membuka Mini App berikutnya.
     */
    public function resetPassword(User $target, ?User $aktor = null): void
    {
        if ($aktor !== null && $aktor->is($target)) {
            throw ValidationException::withMessages(['user' => ['Password akun sendiri tidak dapat direset lewat menu Admin. Minta Admin lain atau gunakan perintah server.']]);
        }

        DB::transaction(function () use ($target): void {
            $target->forceFill([
                'password' => null,
                'password_changed_at' => now(),
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ])->save();

            // Tetap cabut walau password memang sudah null (event updated tidak terpicu).
            $target->cabutSemuaSesi();
        });

        AuditAuth::catat($target, AuditAuth::PASSWORD_DIRESET_ADMIN, $aktor === null ? ['oleh' => 'perintah_server'] : [], $aktor);
    }

    /** Cabut semua sesi pengguna oleh Admin. Mengembalikan jumlah sesi login penuh yang dicabut. */
    public function cabutSesiOlehAdmin(User $admin, User $target): int
    {
        $jumlah = $this->sesi->keluarSemua($target);
        AuditAuth::catat($target, AuditAuth::SESI_DICABUT_ADMIN, ['jumlah_sesi' => $jumlah], $admin);

        return $jumlah;
    }

    /** Identitas (username atau no telepon) dicocokkan HANYA dengan akun $user. */
    public function identitasCocok(User $user, string $identitas): bool
    {
        $identitas = ltrim(trim($identitas), '@');

        if ($this->metode($identitas) === 'username') {
            return $user->username !== null && hash_equals($user->username, mb_strtolower($identitas));
        }

        $masukan = Telepon::normalisasi($identitas);
        $milik = $user->phone === null ? null : (Telepon::normalisasi($user->phone) ?? $user->phone);

        return $masukan !== null && $milik !== null && hash_equals($milik, $masukan);
    }

    private function metode(string $identitas): string
    {
        return preg_match('/^@?[A-Za-z]/', trim($identitas)) === 1 ? 'username' : 'telepon';
    }

    /**
     * Penguncian atomik: setiap percobaan password MEMESAN satu slot (UPDATE bersyarat) sebelum
     * Hash::check, sehingga request paralel tidak bisa melebihi maks_gagal per siklus kunci. Slot
     * dikembalikan (penghitung = 0) bila password benar. Penghitung dibiarkan = maks selama terkunci
     * dan baru dinolkan saat kunci kedaluwarsa.
     */
    private function reservasiPercobaan(User $user, string $deviceHash): void
    {
        $maks = $this->maksGagal();
        $query = fn () => User::withoutGlobalScopes()->whereKey($user->getKey());

        // Kunci kedaluwarsa: buka dan mulai siklus baru (atomik, aman bila beberapa request bersamaan).
        $query()->whereNotNull('locked_until')->where('locked_until', '<=', now())
            ->update(['locked_until' => null, 'failed_login_attempts' => 0]);

        $dipesan = $query()->whereNull('locked_until')->where('failed_login_attempts', '<', $maks)
            ->update(['failed_login_attempts' => DB::raw('failed_login_attempts + 1')]);

        if ($dipesan === 1) {
            return;
        }

        // Terkunci, atau semua slot sedang dipakai request paralel yang belum selesai.
        $sampai = $query()->value('locked_until');
        AuditAuth::catat($user, AuditAuth::DITOLAK_TERKUNCI, ['perangkat' => AuditAuth::sidik($deviceHash)]);

        throw GalatAuth::terkunci($sampai === null ? now()->addMinute() : Carbon::parse($sampai));
    }

    /** Setelah slot dipesan dan password/identitas salah: catat, kunci bila slot habis. */
    private function catatGagal(User $user, string $identitas, bool $cocok, string $deviceHash): never
    {
        $maks = $this->maksGagal();
        $gagal = (int) User::withoutGlobalScopes()->whereKey($user->getKey())->value('failed_login_attempts');

        if ($cocok) {
            AuditAuth::catat($user, AuditAuth::MASUK_GAGAL, ['metode' => $this->metode($identitas), 'gagal_ke' => $gagal, 'perangkat' => AuditAuth::sidik($deviceHash)]);
        } else {
            $this->catatIdentitasAsing($user, $identitas, $deviceHash, 'masuk', $gagal);
        }

        if ($gagal >= $maks) {
            $this->kunci($user);
        }

        throw GalatAuth::kredensialSalah($maks - $gagal);
    }

    private function kunci(User $user): never
    {
        $sampai = now()->addMinutes(max(1, (int) config('kredensial.penguncian.kunci_menit', 15)));
        $baru = User::withoutGlobalScopes()->whereKey($user->getKey())->whereNull('locked_until')->update(['locked_until' => $sampai]);

        if ($baru === 1) {
            AuditAuth::catat($user, AuditAuth::AKUN_TERKUNCI, ['sampai' => $sampai->toIso8601String()]);
        } else {
            // Request paralel lain sudah mengunci lebih dulu.
            $sampai = Carbon::parse(User::withoutGlobalScopes()->whereKey($user->getKey())->value('locked_until') ?? $sampai);
        }

        throw GalatAuth::terkunci($sampai);
    }

    private function maksGagal(): int
    {
        return max(1, (int) config('kredensial.penguncian.maks_gagal', 5));
    }

    private function catatIdentitasAsing(User $user, string $identitas, string $deviceHash, string $proses, ?int $gagal = null): void
    {
        AuditAuth::catat($user, AuditAuth::IDENTITAS_TIDAK_COCOK, array_filter([
            'proses' => $proses,
            'metode' => $this->metode($identitas),
            // Sinyal keamanan: identitas yang diketik milik akun LAIN (tidak pernah dibalas ke klien).
            'milik_akun_lain' => $this->milikAkunLain($user, $identitas),
            'gagal_ke' => $gagal,
            'perangkat' => AuditAuth::sidik($deviceHash),
        ], fn ($v) => $v !== null));
    }

    private function milikAkunLain(User $user, string $identitas): bool
    {
        $identitas = ltrim(trim($identitas), '@');
        $query = DB::table('users')->where('id', '!=', $user->getKey());

        if ($this->metode($identitas) === 'username') {
            return $query->where('username', mb_strtolower($identitas))->exists();
        }

        $normal = Telepon::normalisasi($identitas);

        return $normal !== null && $query->where('phone', $normal)->exists();
    }

    /** @param array<string, mixed> $payload */
    private function terapkanAktivasi(User $user, array $payload): void
    {
        $segar = User::withoutGlobalScopes()->lockForUpdate()->findOrFail($user->getKey());

        if ($segar->punyaPassword()) {
            throw GalatAuth::sudahAktivasi();
        }

        $data = ['password' => (string) ($payload['password_hash'] ?? ''), 'password_changed_at' => now()];

        if (isset($payload['username']) && is_string($payload['username'])) {
            $data['username'] = $payload['username'];
        }

        if (array_key_exists('phone', $payload)) {
            // No telepon milik akun lain tidak ditolak (tanpa oracle), hanya tidak disimpan;
            // Admin melihat kejadiannya di audit dan dapat memperbaiki data.
            if ($this->teleponDipakaiAkunLain($segar, $payload['phone'])) {
                AuditAuth::catat($segar, AuditAuth::TELEPON_TIDAK_DISIMPAN, ['alasan' => 'dipakai_akun_lain']);
            } else {
                $data['phone'] = $payload['phone'];
            }
        }

        if (isset($data['username']) && $this->usernameDipakaiAkunLain($segar, $data['username'])) {
            throw self::usernameTidakTersedia();
        }

        if ($data['password'] === '') {
            throw GalatAuth::token(GalatAuth::TOKEN_TIDAK_VALID, 'Token tidak valid. Ulangi dari awal.');
        }

        if (($segar->username ?? $data['username'] ?? null) === null) {
            throw ValidationException::withMessages(['username' => ['Username wajib diisi.']]);
        }

        try {
            $segar->forceFill($data)->save();
        } catch (UniqueConstraintViolationException) {
            // Balapan: username/no telepon diambil akun lain setelah pemeriksaan (pesan seragam).
            throw self::usernameTidakTersedia();
        }
    }

    private function tolakDev(User $user, PersonalAccessToken $praLogin): void
    {
        if (in_array(SesiService::ABILITY_DEV, (array) $praLogin->abilities, true)) {
            throw GalatAuth::respons(403, GalatAuth::DEV_BYPASS, 'Login kredensial tidak tersedia untuk akun demo bypass. Gunakan akun Telegram sungguhan.');
        }

        if (! in_array(SesiService::ABILITY_PRA_LOGIN, (array) $praLogin->abilities, true) || (int) $praLogin->tokenable_id !== (int) $user->getKey()) {
            throw GalatAuth::respons(403, GalatAuth::BUKAN_PRA_LOGIN, 'Token pra-login tidak valid. Buka ulang Mini App.');
        }
    }
}
