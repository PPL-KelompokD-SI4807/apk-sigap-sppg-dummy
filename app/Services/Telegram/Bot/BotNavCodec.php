<?php

namespace App\Services\Telegram\Bot;

use LogicException;

/**
 * Pembentuk/penguji callback_data NAVIGASI BACA Bot (R-02 v2): `n1.<layar>.<sppg36>.<exp36>.<mac>`.
 *
 * - layar  = id layar pendek (huruf kecil/angka, maks. 8 karakter, diawali huruf).
 * - sppg36 = id SPPG basis 36 tanpa nol di depan; `0` = tanpa SPPG terpilih.
 * - exp36  = waktu kedaluwarsa (detik Unix) basis 36; TTL `telegram.nav_ttl_menit` (bawaan 30 menit).
 * - mac    = 6 byte pertama HMAC-SHA256 atas `n1|layar|sppg36|exp36|telegram_id` (base64url, 8 karakter),
 *   kunci turunan `bot-nav|v1` dari app.key (BERBEDA dari kunci `b1`). Dibandingkan dengan hash_equals.
 *
 * Token dapat dipakai ulang sampai kedaluwarsa dan TIDAK punya baris basis data. Isinya hanya penunjuk
 * layar + SPPG: MAC mencegah pemalsuan/IDOR antar-akun, tetapi BUKAN sumber hak. Setiap klik tetap
 * menghitung ulang pengguna, sesi penuh, peran/RBAC, Policy, dan keanggotaan SPPG (BotNavigasi::buka).
 * Panjang maksimum sekitar 46 byte (batas Telegram 64; ditegakkan saat membentuk).
 */
final class BotNavCodec
{
    public const PREFIX = 'n1';

    /** Batas callback_data Telegram (byte). */
    public const MAKS_BYTE = 64;

    /** Toleransi jam: exp lebih jauh dari TTL + ini di masa depan ditolak (kunci bocor/format lama). */
    private const SKEW_DETIK = 120;

    private const POLA_LAYAR = '/^[a-z][a-z0-9]{0,7}$/D';

    private const POLA = '/^n1\.([a-z][a-z0-9]{0,7})\.(0|[1-9a-z][0-9a-z]{0,9})\.([1-9a-z][0-9a-z]{0,7})\.([A-Za-z0-9_-]{8})$/D';

    /** callback_data untuk tombol navigasi ke $layar (SPPG null = tanpa SPPG terpilih). */
    public static function data(string $layar, ?int $sppgId, int $telegramId, ?int $sekarang = null): string
    {
        if (preg_match(self::POLA_LAYAR, $layar) !== 1) {
            throw new LogicException('Id layar navigasi tidak sah.');
        }

        // Maks. 10 digit basis 36 (sama dengan batas urai); id SPPG nyata jauh di bawahnya.
        if ($sppgId !== null && ($sppgId <= 0 || $sppgId >= 3_656_158_440_062_976)) {
            throw new LogicException('Id SPPG navigasi tidak sah.');
        }

        $exp = ($sekarang ?? time()) + self::ttlDetik();
        $sppg = self::keBasis36($sppgId ?? 0);
        $exp36 = self::keBasis36($exp);
        $data = self::PREFIX.'.'.$layar.'.'.$sppg.'.'.$exp36.'.'.self::mac($layar, $sppg, $exp36, $telegramId);

        if (strlen($data) > self::MAKS_BYTE) {
            throw new LogicException('callback_data navigasi melebihi 64 byte.');
        }

        return $data;
    }

    /** @return array{text: string, callback_data: string} */
    public static function tombol(string $label, string $layar, ?int $sppgId, int $telegramId): array
    {
        return ['text' => mb_substr($label, 0, 64), 'callback_data' => self::data($layar, $sppgId, $telegramId)];
    }

    public static function cocokPrefiks(mixed $data): bool
    {
        return is_string($data) && str_starts_with($data, self::PREFIX.'.');
    }

    /**
     * Tujuan bila format dan MAC cocok untuk telegram_id ini (termasuk yang sudah kedaluwarsa, ditandai
     * `kedaluwarsa=true`); selain itu null. Semua kegagalan (format, MAC, milik telegram_id lain, exp terlalu
     * jauh) mengembalikan null yang sama agar pemanggil menjawab seragam (tanpa oracle).
     */
    public static function urai(mixed $data, int $telegramId, ?int $sekarang = null): ?BotNavTujuan
    {
        if (! is_string($data) || strlen($data) > self::MAKS_BYTE || preg_match(self::POLA, $data, $m) !== 1) {
            return null;
        }

        [, $layar, $sppg36, $exp36, $mac] = $m;

        if (! hash_equals(self::mac($layar, $sppg36, $exp36, $telegramId), $mac)) {
            return null;
        }

        $sppg = self::dariBasis36($sppg36);
        $exp = self::dariBasis36($exp36);
        $sekarang ??= time();

        if ($sppg === null || $exp === null || $exp > $sekarang + self::ttlDetik() + self::SKEW_DETIK) {
            return null;
        }

        return new BotNavTujuan($layar, $sppg === 0 ? null : $sppg, $exp, $exp < $sekarang);
    }

    public static function ttlDetik(): int
    {
        return max(1, (int) config('telegram.nav_ttl_menit', 30)) * 60;
    }

    private static function mac(string $layar, string $sppg36, string $exp36, int $telegramId): string
    {
        $raw = hash_hmac('sha256', self::PREFIX.'|'.$layar.'|'.$sppg36.'|'.$exp36.'|'.$telegramId, self::kunci(), true);

        return rtrim(strtr(base64_encode(substr($raw, 0, 6)), '+/', '-_'), '=');
    }

    private static function kunci(): string
    {
        return hash_hmac('sha256', 'bot-nav|v1', (string) config('app.key'));
    }

    /** Basis 36 dengan aritmetika bilangan bulat (tanpa float, tanpa kehilangan presisi). */
    private static function keBasis36(int $n): string
    {
        if ($n === 0) {
            return '0';
        }

        $s = '';

        while ($n > 0) {
            $s = '0123456789abcdefghijklmnopqrstuvwxyz'[$n % 36].$s;
            $n = intdiv($n, 36);
        }

        return $s;
    }

    /** Maks. 10 digit basis 36 (< 2^52) sehingga tidak pernah meluap. Null bila karakter tidak sah. */
    private static function dariBasis36(string $s): ?int
    {
        if ($s === '' || strlen($s) > 10) {
            return null;
        }

        $n = 0;

        foreach (str_split($s) as $c) {
            $d = strpos('0123456789abcdefghijklmnopqrstuvwxyz', $c);

            if ($d === false) {
                return null;
            }

            $n = $n * 36 + $d;
        }

        return $n;
    }
}
