<?php

namespace App\Services\Auth;

use App\Models\TantanganToken;
use App\Models\User;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramBotService;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Token sekali pakai 6 digit R-03 (aktivasi/atur password dan perangkat baru).
 *
 * - Kode acak kriptografis (random_int), hanya disimpan sebagai HMAC-SHA256(app key, id|kode).
 * - Dikirim bot HANYA ke users.telegram_id dari basis data (tidak pernah ke chat dari input).
 * - Kedaluwarsa config('kredensial.token.berlaku_menit') (5), sekali pakai (klaim atomik),
 *   hangus setelah maks_percobaan salah (total per tantangan, termasuk setelah kirim ulang), kirim ulang dibatasi jeda & jumlah, tantangan baru per
 *   pengguna dibatasi per jendela waktu.
 * - Terikat ke pengguna + token pra-login yang memintanya + perangkat (device_hash); Mini App yang
 *   dibuka ulang (token pra-login baru) wajib mengulang dari awal. Tantangan milik pengguna/perangkat lain
 *   diperlakukan sama dengan tidak ada (tanpa membedakan pesan).
 * - Pesan bot tidak pernah memuat password atau no telepon.
 */
class TokenSekaliPakaiService
{
    public function __construct(private readonly TelegramBotService $bot) {}

    /**
     * Buat tantangan baru dan kirim kodenya ke chat Telegram pengguna.
     *
     * @param  array<string, mixed>  $payload  disimpan terenkripsi sampai tantangan selesai
     * @param  (Closure(): void)|null  $periksa  dijalankan SETELAH jatah tantangan terpakai (pemeriksaan
     *                                           yang bisa menjadi oracle, mis. username tersedia)
     */
    public function buat(User $user, PersonalAccessToken $praLogin, string $tujuan, string $deviceHash, array $payload = [], ?Closure $periksa = null): TantanganToken
    {
        if ($user->telegram_id === null) {
            throw GalatAuth::respons(422, GalatAuth::TELEGRAM_BELUM_TERHUBUNG, 'Akun belum terhubung ke Telegram. Minta tautan undangan kepada Admin.');
        }

        $kunci = 'tantangan-token:'.$user->getKey();
        $maks = max(1, (int) config('kredensial.token.maks_tantangan', 5));

        if (RateLimiter::tooManyAttempts($kunci, $maks)) {
            $detik = RateLimiter::availableIn($kunci);

            throw GalatAuth::respons(429, GalatAuth::TERLALU_SERING, 'Terlalu sering meminta token. Coba lagi dalam '.max(1, (int) ceil($detik / 60)).' menit.', headers: ['Retry-After' => (string) $detik]);
        }

        RateLimiter::hit($kunci, max(60, (int) config('kredensial.token.jendela_tantangan_menit', 30) * 60));

        if ($periksa !== null) {
            $periksa();
        }

        $kode = $this->kodeAcak();

        $tantangan = DB::transaction(function () use ($user, $praLogin, $tujuan, $deviceHash, $payload, $kode): TantanganToken {
            // Satu tantangan terbuka per pengguna+tujuan+perangkat: yang lama hangus.
            $user->tantanganToken()->terbuka()
                ->where('tujuan', $tujuan)->where('device_hash', $deviceHash)
                ->update(['hangus_pada' => now(), 'payload' => null]);

            $tantangan = new TantanganToken;
            $tantangan->forceFill([
                'id' => $tantangan->newUniqueId(),
                'user_id' => $user->getKey(),
                'personal_access_token_id' => $praLogin->getKey(),
                'tujuan' => $tujuan,
                'device_hash' => $deviceHash,
                'percobaan' => 0,
                'kirim_ulang' => 0,
                'terkirim_pada' => now(),
                'kedaluwarsa_pada' => $this->kedaluwarsa(),
                'payload' => $payload === [] ? null : $payload,
            ]);
            $tantangan->forceFill(['kode_hash' => self::hashKode($tantangan->id, $kode)])->save();

            return $tantangan;
        });

        $this->kirim($user, $tantangan, $kode);

        return $tantangan;
    }

    /**
     * Kirim ulang: kode baru (kode lama tidak berlaku) dan masa berlaku diperbarui. Hitungan percobaan
     * salah TIDAK di-reset: total tebakan per tantangan tetap maks_percobaan.
     */
    public function kirimUlang(User $user, PersonalAccessToken $praLogin, string $tantanganId, string $deviceHash): TantanganToken
    {
        $tantangan = $this->milik($user, $praLogin, $tantanganId, $deviceHash);

        if ($tantangan === null || ! $tantangan->terbuka()) {
            throw GalatAuth::token(GalatAuth::TOKEN_TIDAK_VALID, 'Permintaan token tidak berlaku lagi. Ulangi dari awal.');
        }

        if ($tantangan->kirim_ulang >= (int) config('kredensial.token.maks_kirim_ulang', 3)) {
            throw GalatAuth::respons(429, GalatAuth::TERLALU_SERING, 'Batas kirim ulang token tercapai. Ulangi dari awal.');
        }

        $boleh = $tantangan->terkirim_pada->copy()->addSeconds((int) config('kredensial.token.jeda_kirim_ulang_detik', 60));

        if ($boleh->isFuture()) {
            $detik = max(1, (int) ceil(now()->diffInSeconds($boleh, true)));

            throw GalatAuth::respons(429, GalatAuth::TERLALU_SERING, "Tunggu {$detik} detik sebelum meminta token lagi.", headers: ['Retry-After' => (string) $detik]);
        }

        $kode = $this->kodeAcak();

        $diperbarui = TantanganToken::query()->whereKey($tantangan->getKey())
            ->whereNull('dipakai_pada')->whereNull('hangus_pada')
            ->where('kirim_ulang', $tantangan->kirim_ulang)
            ->update([
                'kode_hash' => self::hashKode($tantangan->id, $kode),
                'kirim_ulang' => $tantangan->kirim_ulang + 1,
                'terkirim_pada' => now(),
                'kedaluwarsa_pada' => $this->kedaluwarsa(),
                'updated_at' => now(),
            ]);

        if ($diperbarui !== 1) {
            throw GalatAuth::token(GalatAuth::TOKEN_TIDAK_VALID, 'Permintaan token tidak berlaku lagi. Ulangi dari awal.');
        }

        $tantangan->refresh();
        $this->kirim($user, $tantangan, $kode);

        return $tantangan;
    }

    /**
     * Verifikasi kode lalu jalankan $lanjutan di transaksi yang sama dengan klaim sekali pakai:
     * bila $lanjutan gagal, klaim dibatalkan. Percobaan salah selalu tersimpan.
     *
     * @template T
     *
     * @param  Closure(TantanganToken): T  $lanjutan
     * @return T
     */
    public function verifikasi(User $user, PersonalAccessToken $praLogin, string $tantanganId, string $kode, string $deviceHash, Closure $lanjutan): mixed
    {
        $maks = max(1, (int) config('kredensial.token.maks_percobaan', 5));

        $hasil = DB::transaction(function () use ($user, $praLogin, $tantanganId, $kode, $deviceHash, $lanjutan, $maks): array {
            $tantangan = $this->milik($user, $praLogin, $tantanganId, $deviceHash, kunci: true);

            if ($tantangan === null) {
                return ['galat' => GalatAuth::TOKEN_TIDAK_VALID];
            }

            if ($tantangan->dipakai_pada !== null) {
                return ['galat' => GalatAuth::TOKEN_DIPAKAI, 'tantangan' => $tantangan];
            }

            if ($tantangan->hangus_pada !== null) {
                return ['galat' => GalatAuth::TOKEN_HANGUS, 'tantangan' => $tantangan];
            }

            if (! $tantangan->kedaluwarsa_pada->isFuture()) {
                return ['galat' => GalatAuth::TOKEN_KEDALUWARSA, 'tantangan' => $tantangan];
            }

            if (! hash_equals($tantangan->kode_hash, self::hashKode($tantangan->id, $kode))) {
                TantanganToken::query()->whereKey($tantangan->getKey())->increment('percobaan');
                $percobaan = (int) TantanganToken::query()->whereKey($tantangan->getKey())->value('percobaan');

                if ($percobaan >= $maks) {
                    TantanganToken::query()->whereKey($tantangan->getKey())->update(['hangus_pada' => now(), 'payload' => null]);

                    return ['galat' => GalatAuth::TOKEN_HANGUS, 'tantangan' => $tantangan, 'baru_hangus' => true];
                }

                return ['galat' => GalatAuth::TOKEN_SALAH, 'tantangan' => $tantangan, 'sisa' => $maks - $percobaan];
            }

            // Klaim atomik: hanya satu permintaan yang memakai kode ini.
            $klaim = TantanganToken::query()->whereKey($tantangan->getKey())
                ->whereNull('dipakai_pada')->whereNull('hangus_pada')
                ->update(['dipakai_pada' => now(), 'payload' => null, 'updated_at' => now()]);

            if ($klaim !== 1) {
                return ['galat' => GalatAuth::TOKEN_DIPAKAI, 'tantangan' => $tantangan];
            }

            return ['ok' => $lanjutan($tantangan)];
        });

        if (array_key_exists('ok', $hasil)) {
            return $hasil['ok'];
        }

        $this->gagal($user, $hasil);
    }

    public static function hashKode(string $tantanganId, string $kode): string
    {
        return hash_hmac('sha256', 'token|'.$tantanganId.'|'.$kode, (string) config('app.key'));
    }

    /** @param array{galat: string, tantangan?: TantanganToken, sisa?: int, baru_hangus?: bool} $hasil */
    private function gagal(User $user, array $hasil): never
    {
        $tantangan = $hasil['tantangan'] ?? null;
        $konteks = array_filter([
            'tujuan' => $tantangan?->tujuan,
            'alasan' => $hasil['galat'],
            'sisa_percobaan' => $hasil['sisa'] ?? null,
        ], fn ($v) => $v !== null);

        $event = match (true) {
            $hasil['galat'] === GalatAuth::TOKEN_SALAH => AuditAuth::TOKEN_SALAH,
            ($hasil['baru_hangus'] ?? false) === true => AuditAuth::TOKEN_HANGUS,
            default => AuditAuth::TOKEN_DITOLAK,
        };
        AuditAuth::catat($user, $event, $konteks);

        throw match ($hasil['galat']) {
            GalatAuth::TOKEN_SALAH => GalatAuth::token(GalatAuth::TOKEN_SALAH, "Token salah. Sisa percobaan: {$hasil['sisa']}.", ['sisa_percobaan' => $hasil['sisa']]),
            GalatAuth::TOKEN_HANGUS => GalatAuth::token(GalatAuth::TOKEN_HANGUS, 'Token tidak berlaku lagi karena terlalu banyak percobaan salah. Ulangi dari awal untuk meminta token baru.'),
            GalatAuth::TOKEN_KEDALUWARSA => GalatAuth::token(GalatAuth::TOKEN_KEDALUWARSA, 'Token kedaluwarsa. Minta kirim ulang token.'),
            GalatAuth::TOKEN_DIPAKAI => GalatAuth::token(GalatAuth::TOKEN_DIPAKAI, 'Token sudah dipakai. Ulangi dari awal bila perlu.'),
            default => GalatAuth::token(GalatAuth::TOKEN_TIDAK_VALID, 'Token tidak valid. Ulangi dari awal.'),
        };
    }

    /** Tantangan milik user + token pra-login ini + perangkat ini; selain itu null (tanpa membedakan). */
    private function milik(User $user, PersonalAccessToken $praLogin, string $tantanganId, string $deviceHash, bool $kunci = false): ?TantanganToken
    {
        $tantangan = TantanganToken::query()
            ->whereKey($tantanganId)
            ->where('user_id', $user->getKey())
            ->where('personal_access_token_id', $praLogin->getKey())
            ->when($kunci, fn ($q) => $q->lockForUpdate())
            ->first();

        // Perangkat dibandingkan constant-time; tidak cocok = sama dengan tidak ada.
        return $tantangan !== null && hash_equals($tantangan->device_hash, $deviceHash) ? $tantangan : null;
    }

    private function kirim(User $user, TantanganToken $tantangan, string $kode): void
    {
        $menit = (int) config('kredensial.token.berlaku_menit', 5);
        $untuk = match ($tantangan->tujuan) {
            TantanganToken::TUJUAN_AKTIVASI => 'aktivasi akun',
            default => 'masuk dari perangkat baru',
        };

        $teks = "Token SiGAP SPPG: {$kode}\n\n"
            ."Untuk: {$untuk}.\n"
            ."Berlaku {$menit} menit dan hanya sekali pakai. Ketik token ini di Mini App SiGAP.\n\n"
            .'Jangan berikan token ini kepada siapa pun, termasuk Admin. SiGAP tidak pernah meminta password Anda lewat chat. '
            .'Bila Anda tidak sedang masuk ke SiGAP, abaikan pesan ini dan hubungi Admin.';

        try {
            $this->bot->sendText((int) $user->telegram_id, $teks);
        } catch (TelegramApiException $e) {
            TantanganToken::query()->whereKey($tantangan->getKey())->update(['hangus_pada' => now(), 'payload' => null]);
            Log::warning('auth.token: gagal mengirim token lewat bot.', ['user_id' => $user->getKey(), 'kode_galat' => $e->errorCode]);
            AuditAuth::catat($user, AuditAuth::TOKEN_GAGAL_KIRIM, ['tujuan' => $tantangan->tujuan]);

            $pesan = $e->isPermanent()
                ? 'Token tidak dapat dikirim. Buka chat bot SiGAP di Telegram, tekan Mulai (/start), lalu coba lagi.'
                : 'Token gagal dikirim. Coba lagi sebentar lagi.';

            throw GalatAuth::respons(503, GalatAuth::GAGAL_KIRIM, $pesan);
        }

        AuditAuth::catat($user, AuditAuth::TOKEN_DIKIRIM, ['tujuan' => $tantangan->tujuan, 'kirim_ulang' => $tantangan->kirim_ulang]);
    }

    private function kodeAcak(): string
    {
        $digit = max(6, (int) config('kredensial.token.digit', 6));

        return str_pad((string) random_int(0, (10 ** $digit) - 1), $digit, '0', STR_PAD_LEFT);
    }

    private function kedaluwarsa(): Carbon
    {
        return now()->addMinutes(max(1, (int) config('kredensial.token.berlaku_menit', 5)));
    }
}
