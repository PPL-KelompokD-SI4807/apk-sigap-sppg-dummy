<?php

namespace Tests\Support;

use App\Services\Telegram\InitDataValidator;

/**
 * Membuat initData bertanda tangan seperti yang dikirim Telegram (nilai di-percent-encode).
 */
trait BuildsTelegramInitData
{
    /**
     * @param  array<string, mixed>  $user
     * @param  array<string, string>  $extra
     * @return array<string, string> field (tanpa encode) termasuk hash
     */
    protected function initDataFields(array $user, ?int $authDate = null, array $extra = [], ?string $botToken = null): array
    {
        $fields = array_merge([
            'query_id' => 'AAHdF6IQAAAAAN0XohDhrOrc',
            'user' => json_encode(array_merge([
                'first_name' => 'Siti',
                'last_name' => 'Rahayu',
                'username' => 'siti_dapur',
                'language_code' => 'id',
                'allows_write_to_pm' => true,
            ], $user), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'auth_date' => (string) ($authDate ?? time()),
            'signature' => 'dGVzdC1zaWduYXR1cmUtdGlkYWstZGl2ZXJpZmlrYXNp',
        ], $extra);

        $fields['hash'] = InitDataValidator::sign($fields, $botToken ?? (string) config('telegram.bot_token'));

        return $fields;
    }

    /**
     * @param  array<string, string>  $fields
     */
    protected function encodeInitData(array $fields): string
    {
        return implode('&', array_map(
            fn ($k, $v) => rawurlencode($k).'='.rawurlencode($v),
            array_keys($fields),
            $fields,
        ));
    }

    /**
     * @param  array<string, mixed>  $user
     * @param  array<string, string>  $extra
     */
    protected function makeInitData(array $user, ?int $authDate = null, array $extra = []): string
    {
        return $this->encodeInitData($this->initDataFields($user, $authDate, $extra));
    }
}
