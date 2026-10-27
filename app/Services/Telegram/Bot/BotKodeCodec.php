<?php

namespace App\Services\Telegram\Bot;

/**
 * Pembentuk/penguji callback_data Bot (R-02): `b1.<kode>.<mac>`.
 *
 * - kode = 8 byte acak base64url (11 karakter); sama sekali tidak memuat ID bisnis, versi, SPPG, atau aksi.
 * - mac  = 6 byte pertama HMAC-SHA256 atas `kode|telegram_id` (base64url, 8 karakter), kunci turunan dari
 *   app.key. MAC memangkas tebakan/pemalsuan tanpa menyentuh basis data dan mengikat kode ke satu telegram_id.
 *   MAC BUKAN pengganti pemeriksaan baris/hak: hak selalu dihitung ulang server.
 * Panjang total sekitar 23 byte (batas Telegram 64).
 */
final class BotKodeCodec
{
    public const PREFIX = 'b1';

    private const POLA = '/^b1\.([A-Za-z0-9_-]{11})\.([A-Za-z0-9_-]{8})$/D';

    public static function kodeBaru(): string
    {
        return self::b64(random_bytes(8));
    }

    public static function mac(string $kode, int $telegramId): string
    {
        return self::b64(substr(hash_hmac('sha256', $kode.'|'.$telegramId, self::kunci(), true), 0, 6));
    }

    public static function data(string $kode, int $telegramId): string
    {
        return self::PREFIX.'.'.$kode.'.'.self::mac($kode, $telegramId);
    }

    /** Kode bila format dan MAC cocok untuk telegram_id ini; selain itu null. */
    public static function urai(mixed $data, int $telegramId): ?string
    {
        if (! is_string($data) || strlen($data) > 64 || preg_match(self::POLA, $data, $m) !== 1) {
            return null;
        }

        return hash_equals(self::mac($m[1], $telegramId), $m[2]) ? $m[1] : null;
    }

    private static function kunci(): string
    {
        return hash_hmac('sha256', 'bot-callback|v1', (string) config('app.key'));
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
