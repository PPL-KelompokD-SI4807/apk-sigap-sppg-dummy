<?php

namespace App\Services\Auth;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
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

    public const RESET_BELUM_DISETUJUI = 'reset_belum_disetujui';

    public const PERMINTAAN_TIDAK_AKTIF = 'permintaan_tidak_aktif';

    public const TIDAK_ADA_PEMUTUS = 'tidak_ada_pemutus';

    /** 401: token Bearer dikenali tetapi masa sesinya habis (bergeser/absolut). Klien: tukar initData sekali. */
    public const SESI_KEDALUWARSA = 'sesi_kedaluwarsa';

    /** Penanda atribut request: token yang dikirim ditemukan tetapi kedaluwarsa (diisi SesiService). */
    public const ATRIBUT_SESI_KEDALUWARSA = 'sigap.sesi_kedaluwarsa';

    public const PESAN_TIDAK_TERAUTENTIKASI = 'Tidak terautentikasi. Silakan buka ulang Mini App.';

    public const PESAN_SESI_KEDALUWARSA = 'Sesi Anda telah berakhir. Silakan masuk kembali.';

    /**
     * Badan + header respons 401 rute API. Token kedaluwarsa dibedakan dengan `alasan: sesi_kedaluwarsa`;
     * 401 lain (tanpa token, token dicabut/tidak dikenal) tetap `{"message"}` saja (tanpa oracle token).
     *
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    public static function tidakTerautentikasi(Request $request): array
    {
        if ($request->attributes->get(self::ATRIBUT_SESI_KEDALUWARSA) === true) {
            return [
                ['message' => self::PESAN_SESI_KEDALUWARSA, 'alasan' => self::SESI_KEDALUWARSA],
                ['Cache-Control' => 'no-store, private', 'WWW-Authenticate' => 'Bearer error="invalid_token"'],
            ];
        }

        return [['message' => self::PESAN_TIDAK_TERAUTENTIKASI], []];
    }

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
        return self::respons(409, self::SUDAH_AKTIVASI, 'Akun sudah memiliki password. Gunakan Masuk, atau hubungi Admin bila lupa password.', tambahan: ['langkah' => 'masuk']);
    }

    /** @param array<string, mixed> $tambahan */
    public static function token(string $alasan, string $message, array $tambahan = []): HttpResponseException
    {
        return self::respons(422, $alasan, $message, ['kode' => [$message]], $tambahan);
    }
}
