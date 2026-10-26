<?php

namespace App\Services\Auth;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;

/**
 * Respons galat autentikasi R-03 dalam format API standar {"message", "errors"?} ditambah
 * `alasan` (kode mesin untuk Mini App). Tidak pernah memuat password, token, atau no telepon.
 */
final class GalatAuth
{
    public const PERLU_LOGIN_PENUH = 'perlu_login_penuh';

    public const BUKAN_PRA_LOGIN = 'bukan_pra_login';

    public const KREDENSIAL_SALAH = 'kredensial_salah';

    public const TERKUNCI = 'terkunci';

    public const PERLU_AKTIVASI = 'perlu_aktivasi';

    public const SUDAH_AKTIVASI = 'sudah_aktivasi';

    public const TELEGRAM_BELUM_TERHUBUNG = 'telegram_belum_terhubung';

    public const TERLALU_SERING = 'terlalu_sering';

    public const GAGAL_KIRIM = 'gagal_kirim';

    public const TOKEN_TIDAK_VALID = 'token_tidak_valid';

    public const TOKEN_SALAH = 'token_salah';

    public const TOKEN_KEDALUWARSA = 'token_kedaluwarsa';

    public const TOKEN_DIPAKAI = 'token_dipakai';

    public const TOKEN_HANGUS = 'token_hangus';

    public const DEV_BYPASS = 'dev_bypass';

    /**
     * @param  array<string, list<string>>|null  $errors
     * @param  array<string, mixed>  $tambahan
     * @param  array<string, string>  $headers
     */
    public static function respons(int $status, string $alasan, string $message, ?array $errors = null, array $tambahan = [], array $headers = []): HttpResponseException
    {
        $body = ['message' => $message];

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        $body['alasan'] = $alasan;

        return new HttpResponseException(response()->json($body + $tambahan, $status, $headers + [
            'Cache-Control' => 'no-store, private',
        ]));
    }

    public static function kredensialSalah(int $sisa): HttpResponseException
    {
        $pesan = 'Username/no telepon atau password salah.';

        return self::respons(422, self::KREDENSIAL_SALAH, $pesan, ['identitas' => [$pesan]], ['sisa_percobaan' => $sisa]);
    }

    public static function terkunci(Carbon $sampai): HttpResponseException
    {
        $detik = max(1, (int) ceil(now()->diffInSeconds($sampai, true)));
        $menit = max(1, (int) ceil($detik / 60));

        return self::respons(
            429,
            self::TERKUNCI,
            "Terlalu banyak percobaan masuk yang salah. Akun dikunci sementara, coba lagi dalam {$menit} menit.",
            tambahan: ['terkunci_sampai' => $sampai->toIso8601String()],
            headers: ['Retry-After' => (string) $detik],
        );
    }

    public static function perluAktivasi(): HttpResponseException
    {
        return self::respons(409, self::PERLU_AKTIVASI, 'Akun belum diaktivasi. Atur username dan password terlebih dahulu.', tambahan: ['langkah' => 'aktivasi']);
    }

    public static function sudahAktivasi(): HttpResponseException
    {
        return self::respons(409, self::SUDAH_AKTIVASI, 'Akun sudah memiliki password. Gunakan Masuk atau Lupa password.', tambahan: ['langkah' => 'masuk']);
    }

    /** @param array<string, mixed> $tambahan */
    public static function token(string $alasan, string $message, array $tambahan = []): HttpResponseException
    {
        return self::respons(422, $alasan, $message, ['kode' => [$message]], $tambahan);
    }
}
