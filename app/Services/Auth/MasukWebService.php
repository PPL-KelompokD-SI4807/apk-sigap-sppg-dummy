<?php

namespace App\Services\Auth;

use App\Models\TantanganToken;
use App\Models\User;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramBotService;
use App\Support\Telepon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Cookie;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Alur login web R04-2 (ADR `docs/decisions/2026-11-09-akses-web.md` Keputusan 4, 5, 7; A4, A5, A6, A7, A12).
 *
 * Langkah 1 (masuk): identitas + sandi. Respons SELALU seragam (bentuk + header sama, tepat satu Hash::check):
 * tantangan sungguhan dibuat dan kode 6 digit dikirim bot ke users.telegram_id dari basis data HANYA bila akun ada,
 * aktif, sudah aktivasi, terhubung Telegram, kanal web tidak terkunci, jatah tantangan web tersisa, DAN sandi
 * benar. Selain itu id tantangan tiruan (ULID acak, tidak tersimpan) dan tidak ada pesan terkirim.
 *
 * Langkah 2 (verifikasi): kode dicocokkan dengan hash_equals atas HMAC (kode mentah tidak pernah disimpan), sekali
 * pakai (klaim atomik), kedaluwarsa 5 menit, maks 5 percobaan per tantangan, terikat ke browser yang memintanya
 * (HMAC id pra-login `__Host-sigap_pra`). Semua kegagalan = satu galat seragam. Berhasil -> sesi web (cookie).
 *
 * Penguncian kanal web (users.web_*) terpisah dari Mini App (users.failed_login_attempts/locked_until): gagal sandi
 * atau kode menambah penghitung; 5 gagal -> 15 menit; terkunci lagi dalam 24 jam -> 60 menit.
 *
 * Pengiriman pesan bot ditunda sampai respons terkirim (defer), sehingga waktu respons langkah 1 tidak bergantung
 * pada benar/salahnya sandi; kode mentah hanya hidup di memori proses (tidak masuk antrean/DB/log).
 */
class MasukWebService
{
    /** Prefiks callback_data tombol "Bukan saya": `w1.<ulid tantangan>.<mac>`. */
    public const PREFIKS_BUKAN_SAYA = 'w1';

    private const POLA_BUKAN_SAYA = '/^w1\.([0-9A-Za-z]{26})\.([A-Za-z0-9_-]{8})$/D';

    public const BUKAN_SAYA_DIBATALKAN = 'dibatalkan';

    public const BUKAN_SAYA_SUDAH_DIPAKAI = 'sudah_dipakai';

    public const BUKAN_SAYA_TIDAK_BERLAKU = 'tidak_berlaku';

    public const PESAN_KODE_TIDAK_VALID = 'Kode salah atau kedaluwarsa.';

    public function __construct(
        private readonly SesiService $sesi,
        private readonly TelegramBotService $bot,
    ) {}

    // ------------------------------------------------------------------ langkah 1

    /**
     * @return array{id: string, kedaluwarsa_pada: Carbon, kirim_ulang_tersedia_pada: Carbon}
     */
    public function masuk(string $identitas, string $password, string $idPraLogin, ?string $label): array
    {
        $user = $this->cariAkun($identitas);
        $hasil = $this->periksaLangkah1($user, $password);

        if ($user === null) {
            // Tanpa subjek audit: hanya log aplikasi ber-hash (tanpa identitas mentah).
            Log::info('auth.web.masuk_langkah1', ['hasil' => 'akun_tidak_dikenal', 'identitas' => substr(self::hashIdentitas($identitas), 0, 12)]);

            return $this->deskriptor((string) Str::ulid());
        }

        if ($hasil !== 'sandi_benar') {
            AuditAuth::catat($user, AuditAuth::WEB_MASUK_LANGKAH1, ['hasil' => $hasil, 'metode' => self::metode($identitas)]);

            return $this->deskriptor((string) Str::ulid());
        }

        if ($user->telegram_id === null) {
            AuditAuth::catat($user, AuditAuth::WEB_MASUK_LANGKAH1, ['hasil' => 'telegram_belum_terhubung', 'metode' => self::metode($identitas)]);

            return $this->deskriptor((string) Str::ulid());
        }

        if (! $this->jatahTantangan($user)) {
            AuditAuth::catat($user, AuditAuth::WEB_MASUK_LANGKAH1, ['hasil' => 'terlalu_sering', 'metode' => self::metode($identitas)]);

            return $this->deskriptor((string) Str::ulid());
        }

        $kode = self::kodeAcak();
        $tantangan = DB::transaction(function () use ($user, $idPraLogin, $kode): TantanganToken {
            $hashPra = SesiWeb::hashPraLogin($idPraLogin);

            // Satu tantangan web terbuka per pengguna per browser: yang lama hangus.
            $user->tantanganToken()->terbuka()
                ->where('tujuan', TantanganToken::TUJUAN_MASUK_WEB)->where('device_hash', $hashPra)
                ->update(['hangus_pada' => now(), 'payload' => null]);

            $tantangan = new TantanganToken;
            $tantangan->forceFill([
                'id' => $tantangan->newUniqueId(),
                'user_id' => $user->getKey(),
                'personal_access_token_id' => null,
                'tujuan' => TantanganToken::TUJUAN_MASUK_WEB,
                'device_hash' => $hashPra,
                'percobaan' => 0,
                'kirim_ulang' => 0,
                'terkirim_pada' => now(),
                'kedaluwarsa_pada' => self::kedaluwarsa(),
                'payload' => null,
            ]);
            $tantangan->forceFill(['kode_hash' => TokenSekaliPakaiService::hashKode($tantangan->id, $kode)])->save();

            return $tantangan;
        });

        AuditAuth::catat($user, AuditAuth::WEB_MASUK_LANGKAH1, ['hasil' => 'kode_dibuat', 'metode' => self::metode($identitas)]);
        $this->kirimKodeNanti($user, $tantangan, $kode, $label);

        return $this->deskriptor($tantangan->id);
    }

    /**
     * Tepat SATU Hash::check di setiap cabang (sandi asli atau hash tiruan) agar waktu respons seragam.
     * Penguncian dipesan atomik SEBELUM pemeriksaan sandi (request paralel tidak melampaui maks_gagal).
     */
    private function periksaLangkah1(?User $user, string $password): string
    {
        if ($user === null || ! $user->canAuthenticate() || ! $user->punyaPassword()) {
            Hash::check($password, $this->hashTiruan());

            return match (true) {
                $user === null => 'akun_tidak_dikenal',
                ! $user->canAuthenticate() => 'akun_nonaktif',
                default => 'belum_aktivasi',
            };
        }

        if (! $this->pesanSlot($user)) {
            Hash::check($password, $this->hashTiruan());
            AuditAuth::catat($user, AuditAuth::WEB_DITOLAK_TERKUNCI, ['langkah' => 'masuk']);

            return 'terkunci';
        }

        if (! Hash::check($password, (string) $user->password)) {
            $this->kunciBilaHabis($user);

            return 'sandi_salah';
        }

        // Sandi benar: slot yang dipesan dikembalikan (penghitung baru dinolkan saat login web selesai).
        DB::table('users')->where('id', $user->getKey())->where('web_gagal_login', '>', 0)->decrement('web_gagal_login');

        return 'sandi_benar';
    }

    // ------------------------------------------------------------------ kirim ulang

    /**
     * Kirim ulang kode (kode baru; kode lama tidak berlaku). Respons seragam: tantangan tiruan, milik browser
     * lain, hangus, jeda/batas kirim ulang belum terpenuhi, atau kanal terkunci -> tidak ada pesan, bentuk sama.
     *
     * @return array{id: string, kedaluwarsa_pada: Carbon, kirim_ulang_tersedia_pada: Carbon}
     */
    public function kirimUlang(string $tantanganId, string $idPraLogin, ?string $label): array
    {
        $tantangan = $this->milikBrowser($tantanganId, $idPraLogin);
        $user = $tantangan === null ? null : $this->penggunaSah($tantangan);

        if ($tantangan === null || $user === null || ! $tantangan->terbuka() || $user->telegram_id === null || $this->webTerkunci($user)) {
            return $this->deskriptor($tantanganId);
        }

        $bolehPada = $tantangan->terkirim_pada->copy()->addSeconds(self::jedaKirimUlang());

        if ($tantangan->kirim_ulang >= (int) config('kredensial.token.maks_kirim_ulang', 3) || $bolehPada->isFuture()) {
            return $this->deskriptor($tantanganId);
        }

        $kode = self::kodeAcak();
        $diperbarui = TantanganToken::query()->whereKey($tantangan->getKey())
            ->whereNull('dipakai_pada')->whereNull('hangus_pada')
            ->where('kirim_ulang', $tantangan->kirim_ulang)
            ->update([
                'kode_hash' => TokenSekaliPakaiService::hashKode($tantangan->id, $kode),
                'kirim_ulang' => $tantangan->kirim_ulang + 1,
                'terkirim_pada' => now(),
                'kedaluwarsa_pada' => self::kedaluwarsa(),
                'updated_at' => now(),
            ]);

        if ($diperbarui === 1) {
            $this->kirimKodeNanti($user, $tantangan->refresh(), $kode, $label);
        }

        return $this->deskriptor($tantanganId);
    }

    // ------------------------------------------------------------------ langkah 2

    /**
     * Verifikasi kode lalu terbitkan sesi web dalam transaksi yang sama dengan klaim sekali pakai.
     * Gagal apa pun -> 422 `alasan: kode_tidak_valid` (seragam). $cookieSesiLama = cookie `__Host-sigap_sesi`
     * yang masih dibawa browser ini; bila itu sesi web, dicabut (browser ini mendapat sesi baru).
     *
     * @return array{cookie: Cookie, csrf: string, user: User, expires_at: Carbon, sesi_id: int}
     */
    public function verifikasi(string $tantanganId, string $kode, string $idPraLogin, ?string $label, ?string $cookieSesiLama = null): array
    {
        $maks = max(1, (int) config('kredensial.token.maks_percobaan', 5));

        $hasil = DB::transaction(function () use ($tantanganId, $kode, $idPraLogin, $label, $maks): array {
            $tantangan = $this->milikBrowser($tantanganId, $idPraLogin, kunci: true);

            if ($tantangan === null) {
                return ['galat' => 'tidak_ada'];
            }

            $user = $this->penggunaSah($tantangan);

            if ($user === null) {
                TantanganToken::query()->whereKey($tantangan->getKey())->update(['hangus_pada' => now(), 'payload' => null]);

                return ['galat' => 'akun_tidak_sah'];
            }

            $galat = match (true) {
                $tantangan->dipakai_pada !== null => GalatAuth::TOKEN_DIPAKAI,
                $tantangan->hangus_pada !== null => GalatAuth::TOKEN_HANGUS,
                ! $tantangan->kedaluwarsa_pada->isFuture() => GalatAuth::TOKEN_KEDALUWARSA,
                $this->webTerkunci($user) => GalatAuth::TERKUNCI,
                default => null,
            };

            if ($galat !== null) {
                return ['galat' => $galat, 'user' => $user];
            }

            if (! hash_equals($tantangan->kode_hash, TokenSekaliPakaiService::hashKode($tantangan->id, $kode))) {
                TantanganToken::query()->whereKey($tantangan->getKey())->increment('percobaan');
                $percobaan = (int) TantanganToken::query()->whereKey($tantangan->getKey())->value('percobaan');

                if ($percobaan >= $maks) {
                    TantanganToken::query()->whereKey($tantangan->getKey())->update(['hangus_pada' => now(), 'payload' => null]);
                }

                $this->catatGagalKode($user);

                return ['galat' => $percobaan >= $maks ? GalatAuth::TOKEN_HANGUS : GalatAuth::TOKEN_SALAH, 'user' => $user, 'sisa' => max(0, $maks - $percobaan)];
            }

            $klaim = TantanganToken::query()->whereKey($tantangan->getKey())
                ->whereNull('dipakai_pada')->whereNull('hangus_pada')
                ->update(['dipakai_pada' => now(), 'payload' => null, 'updated_at' => now()]);

            if ($klaim !== 1) {
                return ['galat' => GalatAuth::TOKEN_DIPAKAI, 'user' => $user];
            }

            $sesi = $this->sesi->terbitkanWeb($user, $label);

            DB::table('users')->where('id', $user->getKey())->update(['web_gagal_login' => 0, 'web_terkunci_sampai' => null]);

            return ['ok' => $sesi];
        });

        if (! array_key_exists('ok', $hasil)) {
            if (isset($hasil['user'])) {
                AuditAuth::catat($hasil['user'], AuditAuth::WEB_KODE_GAGAL, array_filter([
                    'alasan' => $hasil['galat'],
                    'sisa_percobaan' => $hasil['sisa'] ?? null,
                ], fn ($v) => $v !== null));
            }

            throw self::kodeTidakValid();
        }

        /** @var array{cookie: Cookie, csrf: string, user: User, expires_at: Carbon, sesi_id: int} $sesi */
        $sesi = $hasil['ok'];
        $this->cabutSesiWebLama($cookieSesiLama, $sesi['sesi_id']);

        AuditAuth::catat($sesi['user'], AuditAuth::WEB_MASUK_BERHASIL, ['jalur' => 'kode', 'sesi_id' => $sesi['sesi_id']]);
        $this->kabariLoginNanti($sesi['user'], $label);

        return $sesi;
    }

    public static function kodeTidakValid(): HttpResponseException
    {
        return GalatAuth::respons(422, GalatAuth::KODE_TIDAK_VALID, self::PESAN_KODE_TIDAK_VALID, ['kode' => [self::PESAN_KODE_TIDAK_VALID]]);
    }

    // ------------------------------------------------------------------ tombol bot "Bukan saya"

    public static function cocokPrefiksBukanSaya(mixed $data): bool
    {
        return is_string($data) && str_starts_with($data, self::PREFIKS_BUKAN_SAYA.'.');
    }

    public static function dataBukanSaya(string $tantanganId, int $telegramId): string
    {
        return self::PREFIKS_BUKAN_SAYA.'.'.$tantanganId.'.'.self::macBukanSaya($tantanganId, $telegramId);
    }

    /**
     * Tombol "Bukan saya" ditekan di chat pribadi $telegramId. MAC mengikat id tantangan ke telegram_id; tantangan
     * wajib login web milik akun dengan telegram_id itu. Tantangan terbuka -> hangus + audit. Tidak mengunci akun
     * (tidak dapat dipakai sebagai alat DoS). Null = data tidak sah/bukan milik pengirim (dijawab seragam).
     */
    public function bukanSaya(mixed $data, int $telegramId): ?string
    {
        if (! is_string($data) || strlen($data) > 64 || preg_match(self::POLA_BUKAN_SAYA, $data, $m) !== 1) {
            return null;
        }

        $id = strtolower($m[1]);

        if (! hash_equals(self::macBukanSaya($id, $telegramId), $m[2])) {
            return null;
        }

        $tantangan = TantanganToken::query()->whereKey($id)->where('tujuan', TantanganToken::TUJUAN_MASUK_WEB)->first();
        $user = $tantangan === null ? null : User::query()->withoutGlobalScopes()->withTrashed()->find($tantangan->user_id);

        if ($tantangan === null || $user === null || $user->telegram_id === null || (int) $user->telegram_id !== $telegramId) {
            return null;
        }

        $dibatalkan = TantanganToken::query()->whereKey($tantangan->getKey())
            ->whereNull('dipakai_pada')->whereNull('hangus_pada')
            ->update(['hangus_pada' => now(), 'payload' => null, 'updated_at' => now()]);

        $status = match (true) {
            $dibatalkan === 1 => self::BUKAN_SAYA_DIBATALKAN,
            $tantangan->dipakai_pada !== null => self::BUKAN_SAYA_SUDAH_DIPAKAI,
            default => self::BUKAN_SAYA_TIDAK_BERLAKU,
        };

        AuditAuth::catat($user, AuditAuth::WEB_BUKAN_SAYA, ['status' => $status, 'tantangan' => substr($id, 0, 10)]);

        return $status;
    }

    private static function macBukanSaya(string $tantanganId, int $telegramId): string
    {
        $kunci = hash_hmac('sha256', 'bukan-saya-web|v1', (string) config('app.key'), true);
        $mac = substr(hash_hmac('sha256', strtolower($tantanganId).'|'.$telegramId, $kunci, true), 0, 6);

        return rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }

    // ------------------------------------------------------------------ penguncian kanal web

    /** Kanal web sedang terkunci (dibaca segar dari basis data). */
    public function webTerkunci(User $user): bool
    {
        $sampai = DB::table('users')->where('id', $user->getKey())->value('web_terkunci_sampai');

        return $sampai !== null && Carbon::parse($sampai)->isFuture();
    }

    /** Pesan satu slot percobaan (atomik). False = terkunci atau semua slot terpakai request paralel. */
    private function pesanSlot(User $user): bool
    {
        $baris = fn () => DB::table('users')->where('id', $user->getKey());

        // Kunci kedaluwarsa: buka dan mulai siklus baru (web_terkunci_terakhir_pada dipertahankan untuk eskalasi).
        $baris()->whereNotNull('web_terkunci_sampai')->where('web_terkunci_sampai', '<=', now())
            ->update(['web_terkunci_sampai' => null, 'web_gagal_login' => 0]);

        return $baris()->whereNull('web_terkunci_sampai')->where('web_gagal_login', '<', self::maksGagal())
            ->increment('web_gagal_login') === 1;
    }

    /** Kode salah: tambah penghitung (bila belum terkunci) lalu kunci bila mencapai batas. */
    private function catatGagalKode(User $user): void
    {
        DB::table('users')->where('id', $user->getKey())->whereNull('web_terkunci_sampai')->increment('web_gagal_login');
        $this->kunciBilaHabis($user);
    }

    private function kunciBilaHabis(User $user): void
    {
        $baris = DB::table('users')->where('id', $user->getKey())->first(['web_gagal_login', 'web_terkunci_terakhir_pada']);

        if ($baris === null || (int) $baris->web_gagal_login < self::maksGagal()) {
            return;
        }

        $jendela = max(1, (int) config('kredensial.web.penguncian.jendela_berulang_jam', 24));
        $berulang = $baris->web_terkunci_terakhir_pada !== null
            && Carbon::parse($baris->web_terkunci_terakhir_pada)->gt(now()->subHours($jendela));
        $menit = $berulang
            ? max(1, (int) config('kredensial.web.penguncian.kunci_berulang_menit', 60))
            : max(1, (int) config('kredensial.web.penguncian.kunci_menit', 15));
        $sampai = now()->addMinutes($menit);

        $baru = DB::table('users')->where('id', $user->getKey())->whereNull('web_terkunci_sampai')
            ->update(['web_terkunci_sampai' => $sampai, 'web_terkunci_terakhir_pada' => now()]);

        if ($baru === 1) {
            AuditAuth::catat($user, AuditAuth::WEB_TERKUNCI, ['menit' => $menit, 'berulang' => $berulang, 'sampai' => $sampai->toIso8601String()]);
        }
    }

    private static function maksGagal(): int
    {
        return max(1, (int) config('kredensial.web.penguncian.maks_gagal', 5));
    }

    // ------------------------------------------------------------------ bantu

    /** Akun (belum terhapus lunak) untuk identitas ter-normalisasi; null bila tidak ada/tidak dikenali. */
    private function cariAkun(string $identitas): ?User
    {
        $identitas = ltrim(trim($identitas), '@');
        $query = User::query()->withoutGlobalScopes()->whereNull('deleted_at');

        if (self::metode($identitas) === 'username') {
            return $query->where('username', mb_strtolower($identitas))->first();
        }

        $telepon = Telepon::normalisasi($identitas);

        return $telepon === null ? null : $query->where('phone', $telepon)->first();
    }

    private static function metode(string $identitas): string
    {
        return preg_match('/^@?[A-Za-z]/', trim($identitas)) === 1 ? 'username' : 'telepon';
    }

    /** HMAC identitas ter-normalisasi (kunci batas laju per identitas dan log akun tak dikenal). */
    public static function hashIdentitas(string $identitas): string
    {
        $identitas = ltrim(trim($identitas), '@');
        $normal = self::metode($identitas) === 'username'
            ? mb_strtolower($identitas)
            : (Telepon::normalisasi($identitas) ?? $identitas);

        return hash_hmac('sha256', 'identitas-web|'.$normal, (string) config('app.key'));
    }

    /** Tantangan login web milik browser ini (HMAC id pra-login, constant-time); selain itu null. */
    private function milikBrowser(string $tantanganId, string $idPraLogin, bool $kunci = false): ?TantanganToken
    {
        $tantangan = TantanganToken::query()
            ->whereKey(strtolower($tantanganId))
            ->where('tujuan', TantanganToken::TUJUAN_MASUK_WEB)
            ->whereNull('personal_access_token_id')
            ->when($kunci, fn ($q) => $q->lockForUpdate())
            ->first();

        return $tantangan !== null && hash_equals($tantangan->device_hash, SesiWeb::hashPraLogin($idPraLogin)) ? $tantangan : null;
    }

    /** Pemilik tantangan yang masih boleh masuk (aktif, belum terhapus, sudah aktivasi); selain itu null. */
    private function penggunaSah(TantanganToken $tantangan): ?User
    {
        $user = User::query()->withoutGlobalScopes()->whereNull('deleted_at')->find($tantangan->user_id);

        return $user !== null && $user->canAuthenticate() && $user->punyaPassword() ? $user : null;
    }

    /** Jatah tantangan web per pengguna per jendela (terpisah dari jatah Mini App `tantangan-token:`). */
    private function jatahTantangan(User $user): bool
    {
        $kunci = 'tantangan-web:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($kunci, max(1, (int) config('kredensial.token.maks_tantangan', 5)))) {
            return false;
        }

        RateLimiter::hit($kunci, max(60, (int) config('kredensial.token.jendela_tantangan_menit', 30) * 60));

        return true;
    }

    /**
     * Hash tiruan berbiaya sama dengan hasher aktif (dibuat sekali per konfigurasi hasher, disimpan di cache) untuk
     * akun tak dikenal/nonaktif/terkunci, agar setiap cabang langkah 1 memanggil Hash::check tepat sekali.
     */
    private function hashTiruan(): string
    {
        $kunci = 'sigap:hash-tiruan:'.hash('sha256', (string) json_encode([config('hashing.driver'), config('hashing.bcrypt'), config('hashing.argon')]));

        return (string) Cache::rememberForever($kunci, fn () => Hash::make(Str::random(40)));
    }

    /**
     * Bentuk tantangan seragam (dihitung dari waktu sekarang + config) untuk tantangan sungguhan maupun tiruan.
     *
     * @return array{id: string, kedaluwarsa_pada: Carbon, kirim_ulang_tersedia_pada: Carbon}
     */
    private function deskriptor(string $id): array
    {
        return [
            'id' => strtolower($id),
            'kedaluwarsa_pada' => self::kedaluwarsa(),
            'kirim_ulang_tersedia_pada' => now()->addSeconds(self::jedaKirimUlang()),
        ];
    }

    private static function kedaluwarsa(): Carbon
    {
        return now()->addMinutes(max(1, (int) config('kredensial.token.berlaku_menit', 5)));
    }

    private static function jedaKirimUlang(): int
    {
        return max(1, (int) config('kredensial.token.jeda_kirim_ulang_detik', 60));
    }

    private static function kodeAcak(): string
    {
        $digit = max(6, (int) config('kredensial.token.digit', 6));

        return str_pad((string) random_int(0, (10 ** $digit) - 1), $digit, '0', STR_PAD_LEFT);
    }

    private function cabutSesiWebLama(?string $cookieSesiLama, int $sesiBaruId): void
    {
        if (! is_string($cookieSesiLama) || $cookieSesiLama === '' || strlen($cookieSesiLama) > 255) {
            return;
        }

        $lama = PersonalAccessToken::findToken($cookieSesiLama);

        if ($lama !== null && (int) $lama->getKey() !== $sesiBaruId && SesiService::tokenWeb($lama)) {
            $lama->delete();
        }
    }

    // ------------------------------------------------------------------ pesan bot

    private function kirimKodeNanti(User $user, TantanganToken $tantangan, string $kode, ?string $label): void
    {
        $chatId = (int) $user->telegram_id;
        $tantanganId = $tantangan->id;
        $kirimUlang = $tantangan->kirim_ulang;
        $teks = $this->teksKode($kode, $label);

        defer(function () use ($user, $chatId, $tantanganId, $kirimUlang, $teks): void {
            try {
                $this->bot->sendMessage($chatId, $teks, [
                    'protect_content' => true,
                    'reply_markup' => ['inline_keyboard' => [[
                        ['text' => 'Bukan saya', 'callback_data' => self::dataBukanSaya($tantanganId, $chatId)],
                    ]]],
                ]);
            } catch (Throwable $e) {
                TantanganToken::query()->whereKey($tantanganId)->update(['hangus_pada' => now(), 'payload' => null]);
                Log::warning('auth.web: gagal mengirim kode lewat bot.', [
                    'user_id' => $user->getKey(),
                    'kode_galat' => $e instanceof TelegramApiException ? $e->errorCode : null,
                ]);
                AuditAuth::catat($user, AuditAuth::WEB_KODE_GAGAL_KIRIM, ['kirim_ulang' => $kirimUlang]);

                return;
            }

            AuditAuth::catat($user, AuditAuth::WEB_KODE_DIKIRIM, ['kirim_ulang' => $kirimUlang]);
        });
    }

    private function kabariLoginNanti(User $user, ?string $label): void
    {
        if ($user->telegram_id === null) {
            return;
        }

        $chatId = (int) $user->telegram_id;
        $teks = 'Login web SiGAP SPPG berhasil dari '.self::label($label).' pada '.self::waktuWib().".\n\n"
            .'Bukan Anda? Segera ubah password lewat Mini App SiGAP (semua sesi lain ikut keluar) dan hubungi Admin.';

        defer(function () use ($user, $chatId, $teks): void {
            try {
                $this->bot->sendText($chatId, $teks);
            } catch (Throwable $e) {
                Log::warning('auth.web: gagal mengirim notifikasi login web.', [
                    'user_id' => $user->getKey(),
                    'kode_galat' => $e instanceof TelegramApiException ? $e->errorCode : null,
                ]);
            }
        });
    }

    private function teksKode(string $kode, ?string $label): string
    {
        $menit = max(1, (int) config('kredensial.token.berlaku_menit', 5));
        $domain = SesiWeb::originDiizinkan() ?? 'situs resmi SiGAP';

        return "Kode MASUK WEB SiGAP: {$kode}\n\n"
            .'Ada permintaan masuk ke SiGAP lewat browser ('.self::label($label).') pada '.self::waktuWib().".\n"
            ."Masukkan kode ini HANYA di {$domain}. Berlaku {$menit} menit dan hanya sekali pakai.\n"
            ."Jangan berikan kode ini kepada siapa pun, termasuk Admin.\n\n"
            .'Bukan Anda yang mencoba masuk? Tekan "Bukan saya", lalu segera ubah password lewat Mini App SiGAP dan hubungi Admin.';
    }

    private static function label(?string $label): string
    {
        $label = $label === null ? '' : trim($label);

        return $label === '' ? 'Browser' : $label;
    }

    private static function waktuWib(): string
    {
        return now()->copy()->setTimezone('Asia/Jakarta')->format('d-m-Y H.i').' WIB';
    }
}
