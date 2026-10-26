<?php

namespace App\Services\Telegram;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Models\Scopes\SppgScope;
use App\Models\User;
use App\Services\Auth\AuditAuth;
use App\Services\Auth\SesiService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Lapis 1 login R-03 (FR-01): initData Telegram divalidasi setiap Mini App dibuka.
 *
 * Hasil `langkah`:
 * - `sesi_aktif` : klien mengirim token login penuh miliknya (header Authorization) untuk akun
 *                  Telegram yang sama dan perangkat (device_id) yang sama; tidak ada token baru.
 *                  Token penuh milik akun LAIN dicabut (SesiService::cabutBilaMilikAkunLain).
 * - `masuk`      : token pra-login diterbitkan; lanjut username/no telepon + password.
 * - `aktivasi`   : token pra-login diterbitkan; akun belum punya password (undangan baru,
 *                  sigap:buat-admin, atau di-reset Admin) wajib atur password dulu.
 * Token pra-login hanya ber-ability auth:pra-login dan tidak pernah membuka data. Bypass dev
 * hanya menerbitkan token pra-login bertanda auth:dev (tidak pernah bisa menjadi login penuh).
 */
class TelegramAuthService
{
    public const DEV_PATTERN = '/^dev_role=([a-z_]{3,20})(?:&dev_unit=([a-z]{3,20}))?$/';

    public function __construct(
        private readonly InitDataValidator $validator,
        private readonly SesiService $sesi,
    ) {}

    /**
     * @return array{langkah: string, token: string|null, expires_at: Carbon|null, user: User}
     */
    public function login(string $initData, ?string $deviceId = null, ?string $bearer = null): array
    {
        $dev = $this->devBypassAllowed() && preg_match(self::DEV_PATTERN, $initData, $m) === 1;
        $user = $dev ? $this->devUser($m[1], $m[2] ?? null) : $this->resolveTelegramUser($initData);

        if (! $user->canAuthenticate()) {
            throw new AccessDeniedHttpException('Akun Anda nonaktif. Hubungi Admin SPPG.');
        }

        $deviceHash = $deviceId === null || $deviceId === '' ? null : SesiService::hashPerangkat($deviceId);

        if (! $dev) {
            $this->sesi->cabutBilaMilikAkunLain($user, $bearer);
        }

        if (! $dev && $this->sesi->sesiAktifUntuk($user, $bearer, $deviceHash)) {
            return ['langkah' => 'sesi_aktif', 'token' => null, 'expires_at' => null, 'user' => $user];
        }

        $pra = $this->sesi->terbitkanPraLogin($user, $deviceHash, $dev);
        $langkah = $user->punyaPassword() ? 'masuk' : 'aktivasi';

        AuditAuth::catat($user, AuditAuth::PRA_LOGIN, array_filter([
            'langkah' => $langkah,
            'perangkat' => AuditAuth::sidik($deviceHash),
            'dev' => $dev ?: null,
        ], fn ($v) => $v !== null));

        return ['langkah' => $langkah, 'token' => $pra['token'], 'expires_at' => $pra['expires_at'], 'user' => $user];
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
            'sppg_id' => $role->tanpaSppgId() ? null : $this->demoSppgId(),
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
