<?php

namespace App\Services\Telegram;

/**
 * Validasi Telegram.WebApp.initData sesuai spesifikasi resmi Telegram Mini Apps:
 *   data_check_string = pasangan "key=value" (nilai ter-URL-decode) semua field kecuali
 *                       `hash`, diurutkan menurut key, digabung "\n".
 *   secret_key        = HMAC_SHA256(key = "WebAppData", msg = bot_token)
 *   hash              = hex(HMAC_SHA256(key = secret_key, msg = data_check_string))
 * Perbandingan memakai hash_equals (constant-time).
 */
class InitDataValidator
{
    public const MAX_LENGTH = 8192;

    public function __construct(
        private readonly ?string $botToken,
        private readonly int $maxAge,
        private readonly int $futureSkew = 60,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            config('telegram.bot_token'),
            (int) config('telegram.init_data_max_age', 86400),
            (int) config('telegram.init_data_future_skew', 60),
        );
    }

    /**
     * @return array{user: array<string, mixed>, auth_date: int, fields: array<string, string>}
     *
     * @throws InvalidInitDataException
     */
    public function validate(string $initData, ?int $now = null): array
    {
        if ($this->botToken === null || $this->botToken === '') {
            // Tanpa token bot, HMAC dapat dihitung siapa pun: tolak semua.
            throw new InvalidInitDataException('Autentikasi Telegram belum dikonfigurasi.');
        }

        if ($initData === '' || strlen($initData) > self::MAX_LENGTH) {
            throw new InvalidInitDataException('initData tidak valid.');
        }

        $fields = $this->parse($initData);
        $hash = $fields['hash'] ?? '';
        unset($fields['hash']);

        if (! is_string($hash) || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
            throw new InvalidInitDataException('Tanda tangan initData tidak valid.');
        }

        if (! hash_equals(self::sign($fields, $this->botToken), $hash)) {
            throw new InvalidInitDataException('Tanda tangan initData tidak valid.');
        }

        $authDate = $fields['auth_date'] ?? '';

        if (preg_match('/^\d{1,12}$/', $authDate) !== 1) {
            throw new InvalidInitDataException('initData tidak valid.');
        }

        $authDate = (int) $authDate;
        $now ??= time();

        if ($authDate > $now + $this->futureSkew) {
            throw new InvalidInitDataException('Waktu initData tidak valid.');
        }

        if ($now - $authDate > $this->maxAge) {
            throw new InvalidInitDataException('initData kedaluwarsa. Tutup lalu buka ulang Mini App.');
        }

        $user = json_decode($fields['user'] ?? '', true, 8);

        if (! is_array($user) || ! isset($user['id']) || ! is_int($user['id']) || $user['id'] <= 0) {
            throw new InvalidInitDataException('Data pengguna Telegram tidak valid.');
        }

        return ['user' => $user, 'auth_date' => $authDate, 'fields' => $fields];
    }

    /**
     * Hitung hash initData (dipakai juga oleh tes untuk membuat initData sah).
     *
     * @param  array<string, string>  $fields  tanpa `hash`
     */
    public static function sign(array $fields, string $botToken): string
    {
        unset($fields['hash']);
        ksort($fields, SORT_STRING);

        $checkString = implode("\n", array_map(
            fn (string $key, string $value) => "{$key}={$value}",
            array_keys($fields),
            $fields,
        ));

        $secret = hash_hmac('sha256', $botToken, 'WebAppData', true);

        return hash_hmac('sha256', $checkString, $secret);
    }

    /**
     * Parser query string ketat (tanpa parse_str agar key tidak dimodifikasi menjadi array/
     * underscore). Key ganda ditolak.
     *
     * @return array<string, string>
     */
    private function parse(string $initData): array
    {
        $fields = [];

        foreach (explode('&', $initData) as $pair) {
            if ($pair === '') {
                continue;
            }

            $parts = explode('=', $pair, 2);

            if (count($parts) !== 2) {
                throw new InvalidInitDataException('initData tidak valid.');
            }

            $key = urldecode($parts[0]);

            if ($key === '' || array_key_exists($key, $fields)) {
                throw new InvalidInitDataException('initData tidak valid.');
            }

            $fields[$key] = urldecode($parts[1]);
        }

        return $fields;
    }
}
