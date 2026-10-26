<?php

namespace App\Services\Telegram;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Models\Scopes\SppgScope;
use App\Models\User;
use App\Support\Rbac;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Menukar initData Telegram dengan token Sanctum (FR-01).
 */
class TelegramAuthService
{
    public const DEV_PATTERN = '/^dev_role=([a-z_]{3,20})(?:&dev_unit=([a-z]{3,20}))?$/';

    public function __construct(private readonly InitDataValidator $validator) {}

    /**
     * @return array{token: string, expires_at: Carbon|null, user: User}
     */
    public function login(string $initData, string $device = 'miniapp'): array
    {
        $user = $this->devBypassAllowed() && preg_match(self::DEV_PATTERN, $initData, $m) === 1
            ? $this->devUser($m[1], $m[2] ?? null)
            : $this->resolveTelegramUser($initData);

        if (! $user->canAuthenticate()) {
            throw new AccessDeniedHttpException('Akun Anda nonaktif. Hubungi Admin SPPG.');
        }

        return $this->issueToken($user, $device);
    }

    /**
     * Bypass hanya untuk pengembangan lokal/tes. Dicek terhadap environment aplikasi,
     * bukan hanya konfigurasi, sehingga TELEGRAM_DEV_BYPASS=true di production tidak berefek.
     * Di 'local', APP_URL juga harus host pengembangan: server publik yang salah diberi
     * APP_ENV=local tetap tidak dapat dimasuki dengan dev_role=admin.
     */
    public function devBypassAllowed(): bool
    {
        if (config('telegram.dev_bypass') !== true || app()->isProduction()) {
            return false;
        }

        return app()->environment('testing')
            || (app()->environment('local') && self::isDevelopmentHost((string) parse_url((string) config('app.url'), PHP_URL_HOST)));
    }

    public static function isDevelopmentHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || preg_match('/\.(localhost|test|local)$/', $host) === 1;
    }

    private function resolveTelegramUser(string $initData): User
    {
        try {
            $payload = $this->validator->validate($initData);
        } catch (InvalidInitDataException $e) {
            throw new UnauthorizedHttpException('Bearer', $e->getMessage(), $e);
        }

        $user = User::query()
            ->withoutGlobalScope(SppgScope::class)
            ->withTrashed()
            ->where('telegram_id', $payload['user']['id'])
            ->first();

        if ($user === null) {
            throw new AccessDeniedHttpException($this->unregisteredMessage());
        }

        if ($user->trashed()) {
            throw new AccessDeniedHttpException('Akun Anda nonaktif. Hubungi Admin SPPG.');
        }

        $username = isset($payload['user']['username']) && is_string($payload['user']['username'])
            ? mb_substr($payload['user']['username'], 0, 64)
            : null;

        if ($username !== null && $username !== $user->telegram_username) {
            // Tanpa event: username bukan data hak akses, tidak perlu audit/pencabutan token.
            User::withoutGlobalScopes()->whereKey($user->getKey())->update(['telegram_username' => $username]);
            $user->setRawAttributes(array_merge($user->getAttributes(), ['telegram_username' => $username]), true);
        }

        return $user;
    }

    /**
     * @return array{token: string, expires_at: Carbon|null, user: User}
     */
    private function issueToken(User $user, string $device): array
    {
        $name = 'miniapp:'.$device;
        $minutes = (int) config('sanctum.expiration');
        $expiresAt = $minutes > 0 ? now()->addMinutes($minutes) : null;

        return DB::transaction(function () use ($user, $name, $expiresAt) {
            // Satu token per perangkat; sekalian bersihkan token kedaluwarsa.
            $user->tokens()
                ->where(fn ($q) => $q->where('name', $name)->orWhere('expires_at', '<', now()))
                ->delete();

            $token = $user->createToken($name, Rbac::abilities($user), $expiresAt);

            $now = now();
            User::withoutGlobalScopes()->whereKey($user->getKey())->update(['last_login_at' => $now]);
            $user->setRawAttributes(array_merge($user->getAttributes(), ['last_login_at' => $now]), true);

            return ['token' => $token->plainTextToken, 'expires_at' => $expiresAt, 'user' => $user];
        });
    }

    private function devUser(string $roleValue, ?string $unitValue): User
    {
        $role = Role::tryFrom($roleValue);

        if ($role === null) {
            throw new UnauthorizedHttpException('Bearer', 'Peran dev tidak dikenal.');
        }

        $unit = $role === Role::Relawan ? (RelawanUnit::tryFrom((string) $unitValue) ?? RelawanUnit::Dapur) : null;
        $key = 'sigap_dev_'.$role->value.($unit ? '_'.$unit->value : '');

        $user = User::query()->withoutGlobalScope(SppgScope::class)->withTrashed()
            ->whereNull('telegram_id')->where('telegram_username', $key)->first();

        if ($user !== null) {
            return $user;
        }

        return User::query()->create([
            'name' => 'Demo '.$role->label().($unit ? ' '.$unit->label() : ''),
            'telegram_id' => null,
            'telegram_username' => $key,
            'role' => $role,
            'unit' => $unit,
            'sppg_id' => $role === Role::Admin ? null : $this->demoSppgId(),
            'is_active' => true,
        ]);
    }

    private function demoSppgId(): int
    {
        $id = DB::table('sppgs')->whereNull('deleted_at')->orderBy('id')->value('id');

        if ($id !== null) {
            return (int) $id;
        }

        return (int) DB::table('sppgs')->insertGetId([
            'kode' => 'SPPG-DEMO',
            'nama' => 'SPPG Demo (fiktif)',
            'alamat' => 'Jl. Contoh No. 1, Kota Fiktif',
            'kapasitas_porsi_harian' => 1000,
            'status_operasional' => 'aktif',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function unregisteredMessage(): string
    {
        $bot = config('telegram.bot_username');
        $link = $bot ? "t.me/{$bot}?start=<token>" : 'tautan undangan /start <token>';

        return "Akun Telegram Anda belum terdaftar di SiGAP SPPG. Minta tautan undangan ({$link}) kepada Admin, buka tautan tersebut di Telegram, lalu buka ulang Mini App.";
    }
}
