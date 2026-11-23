<?php

namespace App\Services\Auth;

use App\Models\BrowserTepercaya;
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
 * Langkah 1 (masuk): identitas + sandi, tepat satu Hash::check di setiap cabang. Hasilnya tiga bentuk saja
 * (revisi keputusan respons seragam, 2026-11-23: pengguna yang salah sandi WAJIB diberi tahu dan tetap di halaman
 * sandi; tantangan tiruan dihapus):
 *
 * 1. EMBER SERAGAM -> 422 `kredensial_salah` + pesan GABUNGAN 'Identitas atau sandi salah.' pada field `password`,
 *    tanpa tantangan apa pun. Lima sebab dijawab dengan respons yang identik (status, badan, header, nama cookie):
 *    akun tidak dikenal, sandi salah, akun nonaktif, belum aktivasi, dan kanal web terkunci. Karena pesannya
 *    digabung dan tidak ada `sisa_percobaan`/`terkunci_sampai`, pihak luar tetap tidak dapat menebak akun mana yang
 *    terdaftar, maupun apakah sebuah akun sedang terkunci atau belum aktivasi.
 * 2. Sandi BENAR tetapi belum dapat lanjut -> 422 dengan `alasan` tersendiri dan pesan yang membantu:
 *    `telegram_belum_terhubung` (hubungkan Telegram lewat Mini App) dan `terlalu_sering` (jatah tantangan habis).
 *    Kedua jalur ini hanya tercapai oleh pihak yang sudah tahu sandi, jadi jujur tanpa memberi jalan enumerasi.
 * 3. Sandi benar dan semua syarat siap -> tantangan sungguhan dibuat, kode 6 digit dikirim bot ke users.telegram_id
 *    dari basis data, respons `langkah: kode` dengan deskriptor keadaan nyata tantangan itu.
 *
 * Yang digabung hanyalah yang dikirim ke peramban: audit tetap mencatat sebab sebenarnya (`sandi_salah`,
 * `akun_tidak_dikenal`, `terkunci`, `akun_nonaktif`, `belum_aktivasi`, ...) untuk penyelidikan.
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

    /** R04-4 (#88) prefiks tombol "Bukan saya" pada notifikasi login web sukses: `w2.<ulid token>.<mac>`. */
    public const PREFIKS_BUKAN_SAYA_LOGIN = 'w2';

    private const POLA_BUKAN_SAYA_LOGIN = '/^w2\.([0-9A-Za-z]{26})\.([A-Za-z0-9_-]{8})$/D';

    public const BUKAN_SAYA_DIBATALKAN = 'dibatalkan';

    /** Kode sudah dipakai (w1) atau notifikasi login sukses (w2): semua sesi web + browser tepercaya dicabut. */
    public const BUKAN_SAYA_SESI_DICABUT = 'sesi_dicabut';

    public const BUKAN_SAYA_SUDAH_DIPAKAI = 'sudah_dipakai';

    public const BUKAN_SAYA_TIDAK_BERLAKU = 'tidak_berlaku';

    public const PESAN_KODE_TIDAK_VALID = 'Kode salah atau kedaluwarsa.';

    /** Pesan GABUNGAN ember seragam langkah 1: tidak pernah menyebut mana yang salah (identitas atau sandi). */
    public const PESAN_SANDI_SALAH = 'Identitas atau sandi salah.';

    /** Sandi benar tetapi akun belum punya telegram_id: aman untuk jujur (hanya pemilik sandi yang sampai sini). */
    public const PESAN_TELEGRAM_BELUM_TERHUBUNG = 'Sandi benar, tetapi akun ini belum terhubung ke Telegram sehingga kode tidak dapat dikirim. Buka Mini App SiGAP di Telegram untuk menghubungkan akun, lalu masuk lagi.';

    /** Sandi benar tetapi jatah tantangan web akun ini habis untuk sementara. */
    public const PESAN_TANTANGAN_HABIS = 'Terlalu banyak permintaan kode untuk akun ini. Tunggu beberapa menit, lalu coba masuk lagi.';

    public function __construct(
        private readonly SesiService $sesi,
        private readonly TelegramBotService $bot,
        private readonly BrowserTepercayaService $browser,
    ) {}

    // ------------------------------------------------------------------ langkah 1

    /**
     * Langkah 1. Sandi benar: `langkah: kode` dengan deskriptor tantangan sungguhan. Lima sebab kegagalan kredensial
     * (akun tidak dikenal, sandi salah, akun nonaktif, belum aktivasi, kanal web terkunci) -> satu ember seragam
     * 422 `kredensial_salah` tanpa tantangan (kredensialSalah()); sandi benar tetapi belum dapat lanjut -> 422
     * `telegram_belum_terhubung` / `terlalu_sering` (langkahKode()).
     *
     * Browser tepercaya (R04-3, cookie `__Host-sigap_tb`): HANYA bila cookie sah, milik akun yang sama, akun bukan
     * Admin, DAN sandi benar -> sesi web langsung (`langkah: selesai`, kode dilewati). Sandi salah dengan browser
     * tepercaya sah tetap masuk ember seragam yang sama (dihitung gagal; hanya catatan auditnya menyebut jalur itu),
     * sehingga tidak ada bentuk respons tambahan yang bisa dipakai membedakan keadaan akun. `lupakan_tb` = cookie
     * yang dibawa tidak dapat berlaku lagi (dinilai dari cookie saja, tidak bergantung pada identitas yang diketik);
     * respons galat tidak pernah membawa cookie, jadi ember seragam selalu bernama-cookie sama (yaitu kosong).
     *
     * @return array{langkah: 'kode', tantangan: array{id: string, kedaluwarsa_pada: Carbon, kirim_ulang_tersedia_pada: Carbon, sisa_kirim_ulang: int}, lupakan_tb: bool}|array{langkah: 'selesai', sesi: array{cookie: Cookie, csrf: string, user: User, expires_at: Carbon, sesi_id: int}, browser_sampai: Carbon, lupakan_tb: bool}
     */
    public function masuk(string $identitas, string $password, string $idPraLogin, ?string $label, ?string $cookieTb = null, ?string $cookieSesiLama = null): array
    {
        $user = $this->cariAkun($identitas);
        $hasil = $this->periksaLangkah1($user, $password);

        $browser = $cookieTb === null ? null : $this->browser->dariCookie($cookieTb);
        $tepercaya = $user !== null && $browser !== null && in_array($hasil, ['sandi_benar', 'sandi_salah'], true)
            && $this->browser->bolehUntuk($browser, $user);
        // Cookie tidak dikenal/kedaluwarsa/dicabut (termasuk baru dicabut karena pemiliknya Admin): hapus di browser.
        $lupakanTb = $cookieTb !== null && ($browser === null || $browser->dicabut_at !== null);

        if ($hasil !== 'sandi_benar') {
            $this->catatGagalKredensial($user, $hasil, $identitas, $tepercaya);

            throw self::kredensialSalah();
        }

        if ($tepercaya) {
            $lewat = $this->lewatiKode($user, $browser, $label, $cookieSesiLama);

            if ($lewat !== null) {
                return ['langkah' => 'selesai', 'sesi' => $lewat['sesi'], 'browser_sampai' => $lewat['browser_sampai'], 'lupakan_tb' => false];
            }
        }

        return ['langkah' => 'kode', 'tantangan' => $this->langkahKode($user, $identitas, $idPraLogin, $label), 'lupakan_tb' => $lupakanTb];
    }

    /**
     * Ember seragam langkah 1: SATU respons untuk akun tidak dikenal, sandi salah, akun nonaktif, belum aktivasi,
     * dan kanal web terkunci. Sengaja tanpa `sisa_percobaan` (akun tak dikenal tidak punya penghitung) dan tanpa
     * `terkunci_sampai` (itu akan menandai akun yang ada dan sedang terkunci), serta tanpa cookie apa pun, sehingga
     * status, badan, dan header kelima sebab itu identik.
     */
    public static function kredensialSalah(): HttpResponseException
    {
        return GalatAuth::respons(422, GalatAuth::KREDENSIAL_SALAH, self::PESAN_SANDI_SALAH, ['password' => [self::PESAN_SANDI_SALAH]]);
    }

    /**
     * Audit sebab SEBENARNYA kegagalan kredensial (yang digabung hanya pesan ke peramban). Akun tidak dikenal tidak
     * punya subjek audit: hanya log aplikasi dengan identitas ter-HMAC (tidak pernah identitas mentah).
     */
    private function catatGagalKredensial(?User $user, string $hasil, string $identitas, bool $tepercaya): void
    {
        if ($user === null) {
            Log::info('auth.web.masuk_langkah1', ['hasil' => $hasil, 'identitas' => substr(self::hashIdentitas($identitas), 0, 12)]);

            return;
        }

        AuditAuth::catat($user, AuditAuth::WEB_MASUK_LANGKAH1, array_filter([
            'hasil' => $hasil,
            'metode' => self::metode($identitas),
            'jalur' => $tepercaya ? 'browser_tepercaya' : null,
        ], fn ($v) => $v !== null));
    }

    /**
     * Sandi benar + browser tepercaya sah: terbitkan sesi web turunan browser (tanpa kode). Baris browser dikunci dan
     * diperiksa ulang di dalam transaksi (pencabutan paralel menang); null = tidak lagi berlaku (lanjut jalur kode).
     *
     * @return array{sesi: array{cookie: Cookie, csrf: string, user: User, expires_at: Carbon, sesi_id: int}, browser_sampai: Carbon}|null
     */
    private function lewatiKode(User $user, BrowserTepercaya $browser, ?string $label, ?string $cookieSesiLama): ?array
    {
        $hasil = DB::transaction(function () use ($user, $browser, $label): ?array {
            $terkunci = BrowserTepercaya::query()->whereKey($browser->getKey())->lockForUpdate()->first();

            if ($terkunci === null || ! $terkunci->berlaku() || (int) $terkunci->user_id !== (int) $user->getKey()) {
                return null;
            }

            $sesi = $this->sesi->terbitkanWeb($user, $label, $terkunci);
            $this->browser->tandaiDipakai($terkunci);
            DB::table('users')->where('id', $user->getKey())->update(['web_gagal_login' => 0, 'web_terkunci_sampai' => null]);

            return ['sesi' => $sesi, 'browser_sampai' => $terkunci->berlaku_sampai->copy()];
        });

        if ($hasil === null) {
            return null;
        }

        $this->cabutSesiWebLama($cookieSesiLama, $hasil['sesi']['sesi_id']);

        AuditAuth::catat($user, AuditAuth::WEB_BROWSER_DIPAKAI, ['browser_id' => (int) $browser->getKey()]);
        AuditAuth::catat($user, AuditAuth::WEB_MASUK_BERHASIL, ['jalur' => 'browser_tepercaya', 'sesi_id' => $hasil['sesi']['sesi_id'], 'browser_id' => (int) $browser->getKey()]);
        $this->kabariLoginNanti($user, $label, browserTepercaya: true);

        return $hasil;
    }

    /**
     * Jalur kode (R04-2), hanya untuk sandi yang BENAR: tantangan sungguhan + kode lewat bot. Dua syarat yang masih
     * bisa menghalangi dijawab jujur dengan `alasan` masing-masing, karena jalur ini hanya tercapai oleh pihak yang
     * sudah memasukkan sandi yang benar sehingga tidak ada informasi enumerasi yang bocor.
     *
     * @return array{id: string, kedaluwarsa_pada: Carbon, kirim_ulang_tersedia_pada: Carbon, sisa_kirim_ulang: int}
     */
    private function langkahKode(User $user, string $identitas, string $idPraLogin, ?string $label): array
    {
        if ($user->telegram_id === null) {
            AuditAuth::catat($user, AuditAuth::WEB_MASUK_LANGKAH1, ['hasil' => 'telegram_belum_terhubung', 'metode' => self::metode($identitas)]);

            throw GalatAuth::respons(422, GalatAuth::TELEGRAM_BELUM_TERHUBUNG, self::PESAN_TELEGRAM_BELUM_TERHUBUNG);
        }

        if (! $this->jatahTantangan($user)) {
            AuditAuth::catat($user, AuditAuth::WEB_MASUK_LANGKAH1, ['hasil' => 'terlalu_sering', 'metode' => self::metode($identitas)]);

            throw GalatAuth::respons(422, GalatAuth::TERLALU_SERING, self::PESAN_TANTANGAN_HABIS);
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

        // Dibangun dari model di memori: kegagalan kirim bot (defer, setelah respons) tidak boleh mengubah respons.
        return $this->deskriptorTantangan($tantangan);
    }

    /**
     * Tepat SATU Hash::check di setiap cabang (sandi asli atau hash tiruan) agar waktu respons seragam: kelima sebab
     * ember seragam tidak boleh dapat dibedakan satu sama lain dari waktu respons.
     * Penguncian dipesan atomik SEBELUM pemeriksaan sandi (request paralel tidak melampaui maks_gagal).
     *
     * @return 'akun_tidak_dikenal'|'akun_nonaktif'|'belum_aktivasi'|'terkunci'|'sandi_salah'|'sandi_benar'
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
     * Kirim ulang kode (kode baru; kode lama tidak berlaku). Respons jujur: yang dikirim selalu keadaan tantangan
     * yang sebenarnya (`kedaluwarsa_pada`, `kirim_ulang_tersedia_pada`, `sisa_kirim_ulang`). Jeda 60 detik belum
     * lewat atau jatah kirim ulang habis -> keadaan tidak bergerak dan tidak ada pesan (peramban melihat hitung
     * mundur yang sama, bukan bentuk "berhasil" palsu). Tantangan mati (dipakai/hangus/kedaluwarsa/tidak
     * dikenal/milik browser lain) dan kanal terkunci -> satu deskriptor mati yang sama.
     *
     * @return array{id: string, kedaluwarsa_pada: Carbon, kirim_ulang_tersedia_pada: Carbon, sisa_kirim_ulang: int}
     */
    public function kirimUlang(string $tantanganId, string $idPraLogin, ?string $label): array
    {
        $tantangan = $this->milikBrowser($tantanganId, $idPraLogin);

        if ($tantangan === null) {
            return $this->deskriptorMati($tantanganId);
        }

        $user = $this->penggunaSah($tantangan);

        if ($user === null || ! $tantangan->terbuka() || ! $tantangan->kedaluwarsa_pada->isFuture()
            || $user->telegram_id === null || $this->webTerkunci($user)) {
            return $this->deskriptorMati($tantanganId);
        }

        $bolehPada = $tantangan->terkirim_pada->copy()->addSeconds(self::jedaKirimUlang());

        if ($tantangan->kirim_ulang >= self::maksKirimUlang() || $bolehPada->isFuture()) {
            return $this->deskriptorTantangan($tantangan);
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
            // Nilai baru disalin ke model di memori (bukan refresh): kegagalan kirim bot terjadi setelah respons.
            $tantangan->forceFill([
                'kirim_ulang' => $tantangan->kirim_ulang + 1,
                'terkirim_pada' => now(),
                'kedaluwarsa_pada' => self::kedaluwarsa(),
            ]);
            $this->kirimKodeNanti($user, $tantangan, $kode, $label);
        }

        return $this->deskriptorTantangan($tantangan);
    }

    // ------------------------------------------------------------------ langkah 2

    /**
     * Verifikasi kode lalu terbitkan sesi web dalam transaksi yang sama dengan klaim sekali pakai.
     * Gagal apa pun -> 422 `alasan: kode_tidak_valid` + `sisa_percobaan` (pesan tetap seragam, tidak dibedakan per
     * sebab); tantangan yang tidak dikenal/mati/milik browser lain dijawab `sisa_percobaan: 0`.
     * $cookieSesiLama = cookie `__Host-sigap_sesi`
     * yang masih dibawa browser ini; bila itu sesi web, dicabut (browser ini mendapat sesi baru).
     *
     * $ingatBrowser (R04-3, sukarela): bila benar dan pengguna BUKAN Admin, browser ini dijadikan browser tepercaya
     * 30 hari (cookie `__Host-sigap_tb`; browser tepercaya lama milik akun yang sama yang dibawa $cookieTb diganti)
     * dan sesi ini menjadi sesi turunannya. Untuk Admin nilai ini diabaikan tanpa galat (Q1).
     *
     * @return array{cookie: Cookie, csrf: string, user: User, expires_at: Carbon, sesi_id: int, ingat_browser: string, browser_cookie: Cookie|null, browser_sampai: Carbon|null}
     */
    public function verifikasi(string $tantanganId, string $kode, string $idPraLogin, ?string $label, ?string $cookieSesiLama = null, bool $ingatBrowser = false, ?string $cookieTb = null): array
    {
        $maks = max(1, (int) config('kredensial.token.maks_percobaan', 5));

        $hasil = DB::transaction(function () use ($tantanganId, $kode, $idPraLogin, $label, $maks, $ingatBrowser, $cookieTb): array {
            $tantangan = $this->milikBrowser($tantanganId, $idPraLogin, kunci: true);

            if ($tantangan === null) {
                // Tidak dikenal atau milik browser lain: dijawab seperti tantangan mati (sisa_percobaan 0).
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

            $browser = null;
            $ingat = 'tidak';

            if ($ingatBrowser && $user->isAdmin()) {
                $ingat = 'diabaikan_admin';
            } elseif ($ingatBrowser) {
                $browser = $this->browser->buat($user, $label, $cookieTb === null ? null : $this->browser->dariCookie($cookieTb));
                $ingat = 'diingat';
            }

            $sesi = $this->sesi->terbitkanWeb($user, $label, $browser['browser'] ?? null);

            DB::table('users')->where('id', $user->getKey())->update(['web_gagal_login' => 0, 'web_terkunci_sampai' => null]);

            return ['ok' => $sesi + [
                'ingat_browser' => $ingat,
                'browser_cookie' => $browser['cookie'] ?? null,
                'browser_sampai' => isset($browser['browser']) ? $browser['browser']->berlaku_sampai->copy() : null,
            ]];
        });

        if (! array_key_exists('ok', $hasil)) {
            if (isset($hasil['user'])) {
                AuditAuth::catat($hasil['user'], AuditAuth::WEB_KODE_GAGAL, array_filter([
                    'alasan' => $hasil['galat'],
                    'sisa_percobaan' => $hasil['sisa'] ?? null,
                ], fn ($v) => $v !== null));
            }

            throw self::kodeTidakValid($hasil['sisa'] ?? 0);
        }

        /** @var array{cookie: Cookie, csrf: string, user: User, expires_at: Carbon, sesi_id: int, ingat_browser: string, browser_cookie: Cookie|null, browser_sampai: Carbon|null} $sesi */
        $sesi = $hasil['ok'];
        $this->cabutSesiWebLama($cookieSesiLama, $sesi['sesi_id']);

        AuditAuth::catat($sesi['user'], AuditAuth::WEB_MASUK_BERHASIL, ['jalur' => 'kode', 'sesi_id' => $sesi['sesi_id'], 'ingat_browser' => $sesi['ingat_browser']]);
        $this->kabariLoginNanti($sesi['user'], $label);

        return $sesi;
    }

    /**
     * Satu galat seragam untuk SEMUA kegagalan langkah 2 (pesan teks tidak pernah dibedakan per sebab, Keputusan
     * R04 dipertahankan), ditambah `sisa_percobaan`: percobaan yang masih boleh dipakai pada tantangan ini. 0 =
     * tantangan sudah mati/tidak dikenal/kanal terkunci.
     */
    public static function kodeTidakValid(int $sisa = 0): HttpResponseException
    {
        return GalatAuth::respons(
            422,
            GalatAuth::KODE_TIDAK_VALID,
            self::PESAN_KODE_TIDAK_VALID,
            ['kode' => [self::PESAN_KODE_TIDAK_VALID]],
            ['sisa_percobaan' => max(0, $sisa)],
        );
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
     * Tombol "Bukan saya" pada pesan kode ditekan di chat pribadi $telegramId. MAC mengikat id tantangan ke
     * telegram_id; tantangan wajib login web milik akun dengan telegram_id itu. Tidak mengunci akun (tidak dapat
     * dipakai sebagai alat DoS). Null = data tidak sah/bukan milik pengirim (dijawab seragam).
     *
     * R04-4 (#88): tantangan terbuka -> hangus; tantangan yang SUDAH dipakai dalam bukan_saya_jam terakhir -> ditandai
     * (hangus_pada, sekali saja). Pada kedua kasus semua sesi web + browser tepercaya akun dicabut (cabutAksesWeb).
     * Tombol lama (di luar jendela atau sudah ditekan) tidak mencabut apa pun.
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

        $dicabut = $dibatalkan === 0 && $tantangan->dipakai_pada !== null && TantanganToken::query()->whereKey($tantangan->getKey())
            ->whereNotNull('dipakai_pada')->whereNull('hangus_pada')
            ->where('dipakai_pada', '>=', now()->subHours(self::jamBukanSaya()))
            ->update(['hangus_pada' => now(), 'updated_at' => now()]) === 1;

        $status = match (true) {
            $dibatalkan === 1 => self::BUKAN_SAYA_DIBATALKAN,
            $dicabut => self::BUKAN_SAYA_SESI_DICABUT,
            $tantangan->dipakai_pada !== null => self::BUKAN_SAYA_SUDAH_DIPAKAI,
            default => self::BUKAN_SAYA_TIDAK_BERLAKU,
        };

        $konteks = ['status' => $status, 'jalur' => 'kode', 'tantangan' => substr($id, 0, 10)];

        if ($dibatalkan === 1 || $dicabut) {
            $konteks += $this->cabutAksesWeb($user);
        }

        AuditAuth::catat($user, AuditAuth::WEB_BUKAN_SAYA, $konteks);

        return $status;
    }

    public static function cocokPrefiksBukanSayaLogin(mixed $data): bool
    {
        return is_string($data) && str_starts_with($data, self::PREFIKS_BUKAN_SAYA_LOGIN.'.');
    }

    public static function dataBukanSayaLogin(string $tokenId, int $telegramId): string
    {
        return self::PREFIKS_BUKAN_SAYA_LOGIN.'.'.$tokenId.'.'.self::mac('bukan-saya-login|v1', $tokenId, $telegramId);
    }

    /**
     * R04-4 (#88): tombol "Bukan saya" pada notifikasi login web sukses. MAC mengikat id token ke telegram_id pengirim
     * (domain HMAC terpisah dari w1); token wajib milik akun dengan telegram_id itu, tujuan bukan_saya_web, belum
     * dipakai/hangus, dan belum kedaluwarsa. Konsumsi atomik (sekali pakai) di dalam transaksi bersama pencabutan:
     * semua sesi web + browser tepercaya + tantangan login web terbuka akun dicabut; sesi Mini App tetap.
     * Null untuk SEMUA kegagalan (data palsu, akun lain, sudah dipakai, kedaluwarsa) tanpa efek dan tanpa audit,
     * sehingga dijawab seragam oleh BotCallbackHandler.
     */
    public function bukanSayaLogin(mixed $data, int $telegramId): ?string
    {
        if (! is_string($data) || strlen($data) > 64 || preg_match(self::POLA_BUKAN_SAYA_LOGIN, $data, $m) !== 1) {
            return null;
        }

        $id = strtolower($m[1]);

        if (! hash_equals(self::mac('bukan-saya-login|v1', $id, $telegramId), $m[2])) {
            return null;
        }

        return DB::transaction(function () use ($id, $telegramId): ?string {
            $token = TantanganToken::query()->whereKey($id)->where('tujuan', TantanganToken::TUJUAN_BUKAN_SAYA_WEB)->lockForUpdate()->first();
            $user = $token === null ? null : User::query()->withoutGlobalScopes()->withTrashed()->find($token->user_id);

            if ($token === null || $user === null || $user->telegram_id === null || (int) $user->telegram_id !== $telegramId) {
                return null;
            }

            $klaim = TantanganToken::query()->whereKey($token->getKey())
                ->whereNull('dipakai_pada')->whereNull('hangus_pada')->where('kedaluwarsa_pada', '>', now())
                ->update(['dipakai_pada' => now(), 'updated_at' => now()]);

            if ($klaim !== 1) {
                return null;
            }

            AuditAuth::catat($user, AuditAuth::WEB_BUKAN_SAYA, [
                'status' => self::BUKAN_SAYA_SESI_DICABUT,
                'jalur' => 'notifikasi',
                'token' => substr($id, 0, 10),
            ] + $this->cabutAksesWeb($user));

            return self::BUKAN_SAYA_SESI_DICABUT;
        });
    }

    /**
     * Cabut semua akses web akun (R04-4, #88): browser tepercaya (beserta sesi turunannya), semua sesi web lain, dan
     * tantangan login web terbuka. Sesi/perangkat Mini App dan token "Bukan saya" lain tidak tersentuh.
     *
     * @return array{sesi_web_dicabut: int, browser_dicabut: int}
     */
    private function cabutAksesWeb(User $user): array
    {
        $sesiTurunan = (int) $user->tokens()->where('name', 'like', SesiService::PREFIX_WEB.'%')->whereNotNull('browser_tepercaya_id')->count();
        $browser = $this->browser->cabutSemua($user, BrowserTepercayaService::ALASAN_BUKAN_SAYA);
        $sesi = $sesiTurunan + (int) $user->tokens()->where('name', 'like', SesiService::PREFIX_WEB.'%')->delete();

        $user->tantanganToken()->terbuka()->where('tujuan', TantanganToken::TUJUAN_MASUK_WEB)
            ->update(['hangus_pada' => now(), 'payload' => null, 'updated_at' => now()]);

        return ['sesi_web_dicabut' => $sesi, 'browser_dicabut' => $browser];
    }

    private static function macBukanSaya(string $tantanganId, int $telegramId): string
    {
        return self::mac('bukan-saya-web|v1', $tantanganId, $telegramId);
    }

    /** MAC 6 byte (8 karakter base64url) atas id + telegram_id dengan kunci turunan app.key per domain. */
    private static function mac(string $domain, string $id, int $telegramId): string
    {
        $kunci = hash_hmac('sha256', $domain, (string) config('app.key'), true);
        $mac = substr(hash_hmac('sha256', strtolower($id).'|'.$telegramId, $kunci, true), 0, 6);

        return rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }

    /** Masa berlaku tombol "Bukan saya" (jam), 1..72. */
    public static function jamBukanSaya(): int
    {
        return min(72, max(1, (int) config('kredensial.web.bukan_saya_jam', 24)));
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
     * Bentuk deskriptor tantangan. Nilainya selalu dihitung dari keadaan nyata baris tantangan_tokens.
     *
     * @param  int  $kedaluwarsa  unix, akhir masa berlaku kode
     * @param  int  $terkirim  unix, kode terakhir dikirim (dasar jeda kirim ulang)
     * @param  int  $kirimUlang  kirim ulang yang sudah terpakai
     * @return array{id: string, kedaluwarsa_pada: Carbon, kirim_ulang_tersedia_pada: Carbon, sisa_kirim_ulang: int}
     */
    private function deskriptor(string $id, int $kedaluwarsa, int $terkirim, int $kirimUlang): array
    {
        return [
            'id' => strtolower($id),
            'kedaluwarsa_pada' => now()->setTimestamp($kedaluwarsa),
            'kirim_ulang_tersedia_pada' => now()->setTimestamp($terkirim)->addSeconds(self::jedaKirimUlang()),
            'sisa_kirim_ulang' => max(0, self::maksKirimUlang() - $kirimUlang),
        ];
    }

    /**
     * Deskriptor dari baris tantangan sungguhan; sudah dipakai/hangus/kedaluwarsa -> deskriptorMati.
     *
     * @return array{id: string, kedaluwarsa_pada: Carbon, kirim_ulang_tersedia_pada: Carbon, sisa_kirim_ulang: int}
     */
    private function deskriptorTantangan(TantanganToken $tantangan): array
    {
        if (! $tantangan->terbuka() || ! $tantangan->kedaluwarsa_pada->isFuture()) {
            return $this->deskriptorMati($tantangan->id);
        }

        return $this->deskriptor(
            $tantangan->id,
            $tantangan->kedaluwarsa_pada->getTimestamp(),
            $tantangan->terkirim_pada->getTimestamp(),
            $tantangan->kirim_ulang,
        );
    }

    /**
     * Tantangan mati: sudah dipakai, hangus, kedaluwarsa, tidak dikenal, milik browser lain, atau kanal web
     * terkunci. SATU bentuk tetap untuk semua sebab itu (angka riwayat tidak ikut dikirim): tidak ada masa berlaku
     * dan tidak ada jatah kirim ulang -> peramban menampilkan "minta kode baru lewat halaman masuk".
     *
     * @return array{id: string, kedaluwarsa_pada: Carbon, kirim_ulang_tersedia_pada: Carbon, sisa_kirim_ulang: int}
     */
    private function deskriptorMati(string $id): array
    {
        return [
            'id' => strtolower($id),
            'kedaluwarsa_pada' => now()->copy(),
            'kirim_ulang_tersedia_pada' => now()->copy(),
            'sisa_kirim_ulang' => 0,
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

    private static function maksKirimUlang(): int
    {
        return max(1, (int) config('kredensial.token.maks_kirim_ulang', 3));
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

    private function kabariLoginNanti(User $user, ?string $label, bool $browserTepercaya = false): void
    {
        if ($user->telegram_id === null) {
            return;
        }

        $chatId = (int) $user->telegram_id;
        $jam = self::jamBukanSaya();
        $teks = ($browserTepercaya ? 'Login web SiGAP SPPG dari browser tepercaya (tanpa kode) berhasil: ' : 'Login web SiGAP SPPG berhasil dari ')
            .self::label($label).' pada '.self::waktuWib().".\n\n"
            ."Bukan Anda? Tekan \"Bukan saya\" (berlaku {$jam} jam) untuk mengeluarkan SEMUA sesi web akun Anda dan mencabut "
            .'browser tepercaya, lalu segera ganti password lewat Mini App SiGAP dan hubungi Admin.';

        defer(function () use ($user, $chatId, $teks): void {
            // R04-4 (#88): token tombol sekali pakai + kedaluwarsa (baris tantangan_tokens, tanpa kode).
            $token = $this->buatTokenBukanSaya($user);

            try {
                $this->bot->sendMessage($chatId, $teks, [
                    'reply_markup' => ['inline_keyboard' => [[
                        ['text' => 'Bukan saya', 'callback_data' => self::dataBukanSayaLogin($token->id, $chatId)],
                    ]]],
                ]);
            } catch (Throwable $e) {
                TantanganToken::query()->whereKey($token->id)->update(['hangus_pada' => now()]);
                Log::warning('auth.web: gagal mengirim notifikasi login web.', [
                    'user_id' => $user->getKey(),
                    'kode_galat' => $e instanceof TelegramApiException ? $e->errorCode : null,
                ]);
            }
        });
    }

    private function buatTokenBukanSaya(User $user): TantanganToken
    {
        $token = new TantanganToken;
        $token->forceFill([
            'id' => $token->newUniqueId(),
            'user_id' => $user->getKey(),
            'personal_access_token_id' => null,
            'tujuan' => TantanganToken::TUJUAN_BUKAN_SAYA_WEB,
            // Tidak ada kode/perangkat: hash acak agar kolom wajib terisi tanpa nilai yang dapat dicocokkan.
            'device_hash' => hash('sha256', random_bytes(32)),
            'kode_hash' => hash('sha256', random_bytes(32)),
            'percobaan' => 0,
            'kirim_ulang' => 0,
            'terkirim_pada' => now(),
            'kedaluwarsa_pada' => now()->addHours(self::jamBukanSaya()),
            'payload' => null,
        ])->save();

        return $token;
    }

    private function teksKode(string $kode, ?string $label): string
    {
        $menit = max(1, (int) config('kredensial.token.berlaku_menit', 5));
        $domain = SesiWeb::originDiizinkan() ?? 'situs resmi SiGAP';

        return "Kode MASUK WEB SiGAP: {$kode}\n\n"
            .'Ada permintaan masuk ke SiGAP lewat browser ('.self::label($label).') pada '.self::waktuWib().".\n"
            ."Masukkan kode ini HANYA di {$domain}. Berlaku {$menit} menit dan hanya sekali pakai.\n"
            ."Jangan berikan kode ini kepada siapa pun, termasuk Admin.\n\n"
            .'Bukan Anda yang mencoba masuk? Tekan "Bukan saya" (semua sesi web akun Anda ikut dikeluarkan), lalu segera ubah password lewat Mini App SiGAP dan hubungi Admin.';
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
