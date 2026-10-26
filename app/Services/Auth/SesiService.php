<?php

namespace App\Services\Auth;

use App\Models\PerangkatTepercaya;
use App\Models\User;
use App\Support\Rbac;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sesi & token Sanctum R-03.
 *
 * - Token pra-login: ability `auth:pra-login` saja, kedaluwarsa config('kredensial.pra_login_menit'),
 *   hanya untuk rute masuk/aktivasi/reset/token. Satu per pengguna per perangkat.
 * - Token login penuh: ability `auth:penuh` + ability peran (Rbac), expires_at null. Terikat ke satu
 *   PerangkatTepercaya (personal_access_tokens.perangkat_tepercaya_id); satu sesi per perangkat.
 *   Berakhir hanya karena logout, keluar semua, atau pencabutan otomatis (User::cabutSemuaSesi).
 * - device_id klien hanya disimpan sebagai HMAC-SHA256 dengan app key.
 */
class SesiService
{
    public const ABILITY_PRA_LOGIN = 'auth:pra-login';

    public const ABILITY_PENUH = 'auth:penuh';

    /** Penanda token pra-login dari bypass dev: tidak pernah boleh ditukar menjadi login penuh. */
    public const ABILITY_DEV = 'auth:dev';

    public const PREFIX_PRA_LOGIN = 'pra-login:';

    public const PREFIX_SESI = 'sesi:';

    public static function hashPerangkat(string $deviceId): string
    {
        return hash_hmac('sha256', 'perangkat|'.$deviceId, (string) config('app.key'));
    }

    /**
     * @return array{token: string, expires_at: Carbon}
     */
    public function terbitkanPraLogin(User $user, ?string $deviceHash, bool $dev = false): array
    {
        $nama = self::PREFIX_PRA_LOGIN.($deviceHash === null ? '-' : substr($deviceHash, 0, 16));
        $expiresAt = now()->addMinutes(max(1, (int) config('kredensial.pra_login_menit', 15)));
        $abilities = $dev ? [self::ABILITY_PRA_LOGIN, self::ABILITY_DEV] : [self::ABILITY_PRA_LOGIN];

        return DB::transaction(function () use ($user, $nama, $expiresAt, $abilities): array {
            // Satu token pra-login per perangkat; token pra-login kedaluwarsa ikut dibersihkan.
            $user->tokens()
                ->where('name', 'like', self::PREFIX_PRA_LOGIN.'%')
                ->where(fn ($q) => $q->where('name', $nama)->orWhere('expires_at', '<', now()))
                ->delete();

            $token = $user->createToken($nama, $abilities, $expiresAt);

            return ['token' => $token->plainTextToken, 'expires_at' => $expiresAt];
        });
    }

    /**
     * Terbitkan token login penuh untuk perangkat (sekaligus menandai perangkat tepercaya).
     * Pemanggil WAJIB sudah memverifikasi password dan, bila perangkat belum tepercaya, token 6 digit.
     *
     * @return array{token: string, user: User}
     */
    public function terbitkanPenuh(User $user, string $deviceHash, ?string $label, ?PersonalAccessToken $praLogin): array
    {
        return DB::transaction(function () use ($user, $deviceHash, $label, $praLogin): array {
            $perangkat = $this->percayai($user, $deviceHash, $label);

            // Satu sesi per perangkat: sesi lama perangkat ini diganti.
            $user->tokens()->where('perangkat_tepercaya_id', $perangkat->getKey())->delete();

            /** @var NewAccessToken $baru */
            $baru = $user->createToken(
                self::PREFIX_SESI.$perangkat->getKey(),
                [self::ABILITY_PENUH, ...Rbac::abilities($user)],
                null,
            );
            $baru->accessToken->forceFill(['perangkat_tepercaya_id' => $perangkat->getKey()])->save();

            $praLogin?->delete();

            $now = now();
            User::withoutGlobalScopes()->whereKey($user->getKey())->update([
                'last_login_at' => $now, 'failed_login_attempts' => 0, 'locked_until' => null,
            ]);
            $user->setRawAttributes(array_merge($user->getAttributes(), [
                'last_login_at' => $now, 'failed_login_attempts' => 0, 'locked_until' => null,
            ]), true);

            return ['token' => $baru->plainTextToken, 'user' => $user];
        });
    }

    /** Perangkat tepercaya aktif milik pengguna untuk device_hash, atau null. */
    public function perangkatAktif(User $user, string $deviceHash): ?PerangkatTepercaya
    {
        return $user->perangkatTepercaya()->aktif()->where('device_hash', $deviceHash)->first();
    }

    /**
     * Apakah token Bearer milik $user adalah sesi login penuh yang masih aktif di perangkat ini
     * (dipakai saat Mini App dibuka ulang: initData valid + sesi tersimpan).
     */
    public function sesiAktifUntuk(User $user, ?string $bearer, ?string $deviceHash): bool
    {
        if ($bearer === null || $bearer === '' || $deviceHash === null) {
            return false;
        }

        $token = PersonalAccessToken::findToken($bearer);

        if (! $token instanceof PersonalAccessToken
            || $token->tokenable_type !== $user->getMorphClass()
            || (int) $token->tokenable_id !== (int) $user->getKey()
            || ! in_array(self::ABILITY_PENUH, (array) $token->abilities, true)
            || ($token->expires_at !== null && $token->expires_at->isPast())
            || $token->getAttribute('perangkat_tepercaya_id') === null) {
            return false;
        }

        return $user->perangkatTepercaya()->aktif()
            ->whereKey($token->getAttribute('perangkat_tepercaya_id'))
            ->where('device_hash', $deviceHash)
            ->exists();
    }

    /**
     * Token login penuh yang dikirim klien bersama initData milik akun LAIN (mis. HP dipakai akun
     * Telegram berbeda) dicabut beserta kepercayaan perangkatnya, karena tidak bisa lagi dipastikan
     * dipegang pemiliknya. Token tidak diikat ke initData di setiap request (akses website R-04).
     */
    public function cabutBilaMilikAkunLain(User $user, ?string $bearer): bool
    {
        if ($bearer === null || $bearer === '') {
            return false;
        }

        $token = PersonalAccessToken::findToken($bearer);
        $pemilik = $token?->tokenable;

        if (! $token instanceof PersonalAccessToken
            || ! $pemilik instanceof User
            || $pemilik->is($user)
            || ! in_array(self::ABILITY_PENUH, (array) $token->abilities, true)) {
            return false;
        }

        $this->logout($pemilik, $token);
        AuditAuth::catat($pemilik, AuditAuth::SESI_DICABUT_AKUN_BERBEDA, [
            'perangkat_id' => $token->getAttribute('perangkat_tepercaya_id'),
        ]);

        return true;
    }

    /**
     * Pemeriksaan sesi untuk Bot (R-02): pengguna aktif dengan minimal satu sesi login penuh.
     * Menu bot yang membuka data wajib memanggil ini sebelum melayani.
     */
    public function punyaSesiPenuh(User $user): bool
    {
        if (! $user->canAuthenticate()) {
            return false;
        }

        return $user->tokens()
            ->where('name', 'like', self::PREFIX_SESI.'%')
            ->whereNotNull('perangkat_tepercaya_id')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();
    }

    /**
     * Daftar sesi login penuh aktif pengguna (terbaru dulu).
     *
     * @return Collection<int, array{id: int, perangkat: string|null, dibuat_pada: string|null, terakhir_dipakai_pada: string|null, diverifikasi_pada: string|null, sesi_ini: bool}>
     */
    public function daftarSesi(User $user, ?int $tokenSaatIniId): Collection
    {
        $tokens = $user->tokens()
            ->where('name', 'like', self::PREFIX_SESI.'%')
            ->whereNotNull('perangkat_tepercaya_id')
            ->orderByDesc('last_used_at')->orderByDesc('id')
            ->get();

        $perangkat = PerangkatTepercaya::query()
            ->where('user_id', $user->getKey())
            ->whereIn('id', $tokens->pluck('perangkat_tepercaya_id')->filter()->all())
            ->get()->keyBy('id');

        return $tokens->map(function (PersonalAccessToken $token) use ($perangkat, $tokenSaatIniId): array {
            $p = $perangkat->get($token->getAttribute('perangkat_tepercaya_id'));

            return [
                'id' => (int) $token->getKey(),
                'perangkat' => $p?->label,
                'dibuat_pada' => $token->created_at?->toIso8601String(),
                'terakhir_dipakai_pada' => $token->last_used_at?->toIso8601String(),
                'diverifikasi_pada' => $p?->diverifikasi_pada?->toIso8601String(),
                'sesi_ini' => $tokenSaatIniId !== null && (int) $token->getKey() === $tokenSaatIniId,
            ];
        })->values();
    }

    /** Logout perangkat ini: token dihapus dan kepercayaan perangkat dicabut. */
    public function logout(User $user, PersonalAccessToken $token): void
    {
        DB::transaction(function () use ($user, $token): void {
            $perangkatId = $token->getAttribute('perangkat_tepercaya_id');
            $token->delete();

            if ($perangkatId !== null) {
                $user->perangkatTepercaya()->aktif()->whereKey($perangkatId)->update(['dicabut_at' => now()]);
            }
        });
    }

    /** Keluar dari semua perangkat (termasuk perangkat ini). Mengembalikan jumlah sesi penuh yang dicabut. */
    public function keluarSemua(User $user): int
    {
        return DB::transaction(function () use ($user): int {
            $jumlah = $user->tokens()->where('name', 'like', self::PREFIX_SESI.'%')->count();
            $user->cabutSemuaSesi();

            return $jumlah;
        });
    }

    private function percayai(User $user, string $deviceHash, ?string $label): PerangkatTepercaya
    {
        $label = $label === null ? null : (mb_substr(trim(strip_tags($label)), 0, 120) ?: null);

        /** @var PerangkatTepercaya $perangkat */
        $perangkat = $user->perangkatTepercaya()->firstOrNew(['device_hash' => $deviceHash]);
        $perangkat->forceFill([
            'label' => $label ?? $perangkat->label,
            'diverifikasi_pada' => $perangkat->exists && $perangkat->dicabut_at === null ? $perangkat->diverifikasi_pada : now(),
            'dicabut_at' => null,
        ])->save();

        return $perangkat;
    }
}
