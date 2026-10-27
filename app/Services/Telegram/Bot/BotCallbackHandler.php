<?php

namespace App\Services\Telegram\Bot;

use App\Models\BotAksi;
use App\Models\User;
use App\Services\Auth\SesiService;
use App\Services\Telegram\Bot\Layar\KonteksLayar;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramUpdateHandler;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Router callback_query & balasan catatan Bot (R-02).
 *
 * Urutan verifikasi tiap callback (WAJIB, jangan diubah):
 *   1. rate limit per telegram_id;
 *   (percabangan: prefiks `n1` = navigasi baca dapat dipakai ulang -> navigasiBaca(), urutan langkah sama;
 *    selain itu jalur aksi `b1` sekali pakai di bawah);
 *   2. MAC callback_data + muat baris + status/kedaluwarsa + ikatan from.id/chat.id/user_id;
 *   3. muat ulang pengguna dari basis data (aktif);
 *   4. SesiService::punyaSesiPenuh (tanpa sesi: petunjuk login, bukan data, token tidak dihabiskan);
 *   5. hak dihitung ulang (Rbac::canDecide + pivot + SPPG record + Policy) -> BotKeputusanService::periksa;
 *      aksi tulis (PR-3: kirim ulang rekap, mulai perjalanan, tindak lanjut keluhan) -> BotAksiTulisService::periksa
 *      (peran + RBAC + SPPG + Policy + versi yang dilihat);
 *   6. konsumsi token atomik, lalu panggil service bisnis.
 * Isi callback_data tidak pernah menjadi sumber hak akses.
 *
 * Jalur `n1` (R-02 v2): 2. MAC + kedaluwarsa + ikatan telegram_id (chat.id == from.id); 3. muat ulang pengguna;
 * 4. sesi penuh; 5-6. BotNavigasi::buka (RBAC peran saat ini, SPPG/pivot, Policy, render). Tanpa baris DB.
 */
class BotCallbackHandler
{
    public const TIDAK_BERLAKU = 'Tombol sudah tidak berlaku. Ketik /menu.';

    public const TIDAK_BERHAK = 'Anda tidak berhak melakukan aksi ini.';

    public const TIDAK_BERHAK_LIHAT = 'Anda tidak berhak membuka layar ini.';

    public const PILIH_SPPG = 'Pilih SPPG terlebih dahulu lewat /menu.';

    public const SUDAH_TERBARU = 'Sudah yang terbaru.';

    public const MENU_DIPERBARUI = 'Menu diperbarui.';

    /** Teks netral pengganti layar data lama saat klik ditolak (tanpa data, tanpa tombol). */
    public const LAYAR_DITUTUP_SESI = 'Sesi Anda berakhir. Masuk lewat Mini App untuk melanjutkan.';

    public const LAYAR_DITUTUP_HAK = 'Layar ini tidak lagi tersedia untuk akun Anda. Ketik /menu.';

    public const PERLU_MASUK_SINGKAT = 'Masuk lewat Mini App terlebih dahulu.';

    private const TAMPIL_EDIT = 'edit';

    private const TAMPIL_TIDAK_BERUBAH = 'tidak_berubah';

    private const TAMPIL_KIRIM = 'kirim';

    public function __construct(
        private readonly TelegramBotService $bot,
        private readonly BotAksiService $aksi,
        private readonly SesiService $sesi,
        private readonly BotKeputusanService $keputusan,
        private readonly BotNavigasi $nav,
        private readonly BotAksiTulisService $tulis,
    ) {}

    /** @param array<string, mixed> $query objek CallbackQuery Telegram */
    public function handle(array $query): void
    {
        $id = is_string($query['id'] ?? null) ? $query['id'] : null;
        $from = is_array($query['from'] ?? null) ? $query['from'] : [];
        $fromId = $from['id'] ?? null;
        $message = is_array($query['message'] ?? null) ? $query['message'] : [];
        $chatId = $message['chat']['id'] ?? null;
        $messageId = is_int($message['message_id'] ?? null) ? $message['message_id'] : null;

        // Bukan callback dari manusia di chat pribadi: hanya hentikan indikator tunggu, tanpa memproses.
        if (! is_int($fromId) || ($from['is_bot'] ?? false) === true || ! is_int($chatId) || ($message['chat']['type'] ?? null) !== 'private') {
            $this->jawab($id);

            return;
        }

        // 1. Rate limit.
        if (RateLimiter::tooManyAttempts('tg:cbgagal:'.$fromId, (int) config('telegram.callback_gagal_rate', 10))) {
            $this->jawab($id);

            return;
        }

        if (! RateLimiter::attempt('tg:cb:'.$fromId, (int) config('telegram.callback_rate', 30), fn () => true, 60)) {
            $this->jawab($id, 'Terlalu cepat. Coba lagi sebentar.');

            return;
        }

        // Percabangan token: n1 = navigasi baca (dapat dipakai ulang, tanpa baris DB); selain itu b1.
        if (BotNavCodec::cocokPrefiks($query['data'] ?? null)) {
            $this->navigasiBaca($id, $query['data'], $fromId, $chatId, $messageId);

            return;
        }

        // 2-3. MAC + baris + ikatan + pengguna dimuat ulang. Semua kegagalan dijawab seragam (tanpa oracle).
        $user = $this->cariUser($fromId);
        $aksi = $this->aksi->temukan($query['data'] ?? null, $fromId);

        if ($aksi === null || $user === null || ! $user->canAuthenticate() || ! $this->aksi->berlaku($aksi, $fromId, $chatId, $user)) {
            RateLimiter::hit('tg:cbgagal:'.$fromId, 60);
            $this->jawab($id, self::TIDAK_BERLAKU);

            return;
        }

        $tulis = BotAksiTulisService::milik($aksi->jenis);

        // 4. Sesi login penuh.
        if (! $this->sesi->punyaSesiPenuh($user)) {
            $this->aksi->batalkanCatatan($fromId);
            $this->jawab($id, self::PERLU_MASUK_SINGKAT);

            // Aksi tulis (R-02 PR-3): layar asal (baca/konfirmasi) dinetralkan seperti pola n1; token tidak dihabiskan.
            if ($tulis) {
                $this->netralkan($chatId, $messageId, self::LAYAR_DITUTUP_SESI);
            }

            $this->kirim($chatId, new BotLayar(TelegramUpdateHandler::PERLU_MASUK, $this->bukaAplikasi(), false));
            $this->catat($aksi, $user, 'tanpa_sesi');

            return;
        }

        // 5-7 aksi tulis berkonfirmasi (kirim ulang rekap, mulai perjalanan, tindak lanjut keluhan).
        if ($tulis) {
            $this->aksiTulis($id, $user, $aksi, $fromId, $chatId, $messageId);

            return;
        }

        // 5. Hak dihitung ulang.
        $tolak = $this->keputusan->periksa($user, $aksi);

        if ($tolak !== null) {
            $this->jawab($id, $tolak === BotKeputusanService::TIDAK_ADA ? 'Data tidak ditemukan atau sudah tidak berlaku.' : self::TIDAK_BERHAK, alert: true);
            $this->catat($aksi, $user, $tolak);

            return;
        }

        // 6. Konsumsi atomik, lalu aksi.
        $ke = $aksi->jenis === BotAksi::JENIS_TOLAK ? BotAksi::STATUS_MENUNGGU_CATATAN : BotAksi::STATUS_DIPAKAI;

        if (! $this->aksi->konsumsi($aksi, $ke)) {
            RateLimiter::hit('tg:cbgagal:'.$fromId, 60);
            $this->jawab($id, self::TIDAK_BERLAKU);

            return;
        }

        $this->jawab($id);

        try {
            match ($aksi->jenis) {
                BotAksi::JENIS_DAFTAR => $this->navigasi($aksi, $chatId, $messageId, $this->keputusan->mulai($user, $chatId, $aksi->sppg_id === null ? null : (int) $aksi->sppg_id)),
                BotAksi::JENIS_PILIH_SPPG => $this->navigasi($aksi, $chatId, $messageId, $this->keputusan->daftar($user, $chatId, (int) $aksi->sppg_id)),
                BotAksi::JENIS_TINJAU => $this->navigasi($aksi, $chatId, $messageId, $this->keputusan->tinjau($user, $chatId, $aksi)),
                BotAksi::JENIS_SETUJU => $this->setujui($user, $aksi, $chatId, $messageId),
                BotAksi::JENIS_TOLAK => $this->mintaCatatan($user, $aksi, $chatId, $messageId),
                default => null,
            };
        } catch (\Throwable $e) {
            BotGalat::catat($e, 'callback');
            $this->aksi->selesai($aksi, 'gagal');
            $this->kirim($chatId, new BotLayar('Terjadi kesalahan. Ketik /menu untuk memulai lagi.', null, false));
            $this->catat($aksi, $user, 'gagal');
        }
    }

    /**
     * Balasan teks pengguna ke pesan ForceReply "alasan penolakan". Mengembalikan true bila pesan ini
     * milik alur catatan (APA PUN hasilnya) sehingga TIDAK boleh diteruskan ke Agen AI. Pemanggil sudah
     * memastikan pengguna aktif; sesi penuh diperiksa ulang di sini.
     *
     * @param  array<string, mixed>  $message
     */
    public function balasanCatatan(User $user, int $chatId, array $message): bool
    {
        $replyId = $message['reply_to_message']['message_id'] ?? null;

        if (! is_int($replyId) || $user->telegram_id === null) {
            return false;
        }

        $aksi = $this->aksi->untukPrompt((int) $user->telegram_id, $chatId, $replyId);

        if ($aksi === null || (int) $aksi->user_id !== (int) $user->getKey()) {
            return false;
        }

        $kunci = 'tg:catatangagal:'.$user->telegram_id;

        // Terlalu banyak balasan alasan yang gagal: dijawab diam (tetap milik alur ini, tidak ke Agen AI).
        if (RateLimiter::tooManyAttempts($kunci, (int) config('telegram.catatan_gagal_rate', 20))) {
            return true;
        }

        if (! $this->sesi->punyaSesiPenuh($user)) {
            RateLimiter::hit($kunci, 60);
            $this->aksi->batalkanCatatan((int) $user->telegram_id);
            $this->kirim($chatId, new BotLayar(TelegramUpdateHandler::PERLU_MASUK, $this->bukaAplikasi(), false));
            $this->catat($aksi, $user, 'tanpa_sesi');

            return true;
        }

        if ($aksi->status !== BotAksi::STATUS_MENUNGGU_CATATAN || $aksi->catatan_expires_at === null || $aksi->catatan_expires_at->isPast()) {
            RateLimiter::hit($kunci, 60);
            $this->kirim($chatId, new BotLayar('Waktu membalas alasan penolakan sudah habis. Ketik /menu untuk memulai lagi.', null, false));
            $this->catat($aksi, $user, 'catatan_kedaluwarsa');

            return true;
        }

        $teks = $message['text'] ?? null;

        if (! is_string($teks)) {
            RateLimiter::hit($kunci, 60);
            $this->kirim($chatId, new BotLayar('Kirim alasan penolakan sebagai teks, atau ketik /batal.', null, false));

            return true;
        }

        $tolak = $this->keputusan->periksa($user, $aksi);

        if ($tolak !== null) {
            RateLimiter::hit($kunci, 60);
            $this->aksi->batalkanCatatan((int) $user->telegram_id);
            $this->kirim($chatId, new BotLayar($tolak === BotKeputusanService::TIDAK_ADA ? 'Data tidak ditemukan atau sudah tidak berlaku.' : self::TIDAK_BERHAK, null, false));
            $this->catat($aksi, $user, $tolak);

            return true;
        }

        $cek = $this->keputusan->validasiCatatan((string) $aksi->subjek_tipe, $teks);

        if (! $cek['ok']) {
            // Gagal validasi tidak menghabiskan jendela: pengguna boleh membalas ulang.
            RateLimiter::hit($kunci, 60);
            $this->kirim($chatId, new BotLayar((string) $cek['pesan'], null, false));

            return true;
        }

        if (! $this->aksi->klaimCatatan($aksi)) {
            RateLimiter::hit($kunci, 60);
            $this->kirim($chatId, new BotLayar('Waktu membalas alasan penolakan sudah habis. Ketik /menu untuk memulai lagi.', null, false));
            $this->catat($aksi, $user, 'catatan_kedaluwarsa');

            return true;
        }

        try {
            $hasil = $this->keputusan->putuskan($user, $aksi, 'ditolak', (string) $cek['catatan'], $chatId);
            $this->aksi->selesai($aksi, $hasil['hasil']);
            $this->kirim($chatId, $hasil['layar']);
            $this->catat($aksi, $user, $hasil['hasil']);
        } catch (\Throwable $e) {
            BotGalat::catat($e, 'catatan');
            $this->aksi->selesai($aksi, 'gagal');
            $this->kirim($chatId, new BotLayar('Terjadi kesalahan. Ketik /menu untuk memulai lagi.', null, false));
        }

        return true;
    }

    /** Sesi penuh hilang: tutup jendela catatan yang masih terbuka (tanpa menyentuh data bisnis). */
    public function tutupProsesTanpaSesi(User $user): void
    {
        if ($user->telegram_id !== null) {
            $this->aksi->batalkanCatatan((int) $user->telegram_id);
        }
    }

    /** /batal: batalkan jendela catatan yang terbuka. */
    public function batal(User $user, int $chatId): void
    {
        $jumlah = $user->telegram_id === null ? 0 : $this->aksi->batalkanCatatan((int) $user->telegram_id);

        $this->kirim($chatId, new BotLayar($jumlah > 0 ? 'Penolakan dibatalkan. Tidak ada perubahan data.' : 'Tidak ada proses yang perlu dibatalkan.', null, false));
    }

    // ------------------------------------------------------------------ navigasi baca (n1)

    /**
     * Klik tombol navigasi `n1`. Callback dijawab TEPAT SEKALI di akhir (finally), setelah layar diedit, sehingga
     * edit tanpa perubahan dapat dijawab "Sudah yang terbaru". Kegagalan token/pengguna dijawab seragam.
     */
    private function navigasiBaca(?string $id, string $data, int $fromId, int $chatId, ?int $messageId): void
    {
        $jawaban = null;
        $alert = false;

        try {
            // 2. MAC + kedaluwarsa + ikatan telegram_id; chat pribadi milik pengirim sendiri. 3. Pengguna dimuat ulang.
            $tujuan = BotNavCodec::urai($data, $fromId);
            $user = $tujuan === null || $chatId !== $fromId ? null : $this->cariUser($fromId);

            if ($tujuan === null || $user === null || ! $user->canAuthenticate()) {
                RateLimiter::hit('tg:cbgagal:'.$fromId, 60);
                $jawaban = self::TIDAK_BERLAKU;

                return;
            }

            // 4. Sesi login penuh: hanya tombol masuk, tanpa data; jendela catatan ditutup.
            if (! $this->sesi->punyaSesiPenuh($user)) {
                $this->aksi->batalkanCatatan($fromId);
                $jawaban = self::PERLU_MASUK_SINGKAT;
                $this->netralkan($chatId, $messageId, self::LAYAR_DITUTUP_SESI);
                $this->kirim($chatId, new BotLayar(TelegramUpdateHandler::PERLU_MASUK, $this->bukaAplikasi(), false));
                Log::info('bot.nav', ['layar' => $tujuan->layar, 'hasil' => 'tanpa_sesi', 'user_id' => (int) $user->getKey()]);

                return;
            }

            // Kedaluwarsa: bukan galat, beranda segar dirender di tempat (hak tetap dicek ulang).
            $hasil = $tujuan->kedaluwarsa
                ? $this->nav->buka($user, BotLayarRegistry::BERANDA, null, new KonteksLayar($chatId, $fromId, self::MENU_DIPERBARUI))
                : $this->nav->buka($user, $tujuan->layar, $tujuan->sppgId, new KonteksLayar($chatId, $fromId));

            switch ($hasil->status) {
                case BotNavHasil::OK:
                    if ($hasil->layar !== null && $this->tampilkan($chatId, $messageId, $hasil->layar) === self::TAMPIL_TIDAK_BERUBAH) {
                        $jawaban = self::SUDAH_TERBARU;
                    }
                    break;

                case BotNavHasil::TIDAK_BERHAK:
                    [$jawaban, $alert] = [self::TIDAK_BERHAK_LIHAT, true];
                    $this->netralkan($chatId, $messageId, self::LAYAR_DITUTUP_HAK);
                    break;

                case BotNavHasil::PILIH_SPPG:
                    [$jawaban, $alert] = [self::PILIH_SPPG, true];
                    break;

                case BotNavHasil::TERLALU_CEPAT:
                    $jawaban = 'Terlalu cepat. Coba lagi sebentar.';
                    break;

                default:
                    RateLimiter::hit('tg:cbgagal:'.$fromId, 60);
                    $jawaban = self::TIDAK_BERLAKU;
            }
        } catch (\Throwable $e) {
            BotGalat::catat($e, 'navigasi');
            $jawaban = 'Terjadi kesalahan. Ketik /menu untuk memulai lagi.';
        } finally {
            $this->jawab($id, $jawaban, $alert);
        }
    }

    /**
     * Ganti pesan asal (yang mungkin berisi data lama) dengan teks netral TANPA keyboard dan tanpa data. Bila edit
     * gagal (pesan terlalu lama/dihapus) tidak ada pesan data baru yang dikirim; hanya galat dicatat (kode saja).
     */
    private function netralkan(int $chatId, ?int $messageId, string $teks): void
    {
        if ($messageId === null) {
            return;
        }

        try {
            $this->bot->editMessageText($chatId, $messageId, $teks);
        } catch (TelegramApiException $e) {
            Log::info('bot.netralkan_gagal', ['kode' => $e->errorCode]);
        }
    }

    // ------------------------------------------------------------------ aksi tulis (R-02 PR-3)

    /**
     * Langkah 5-7 untuk token aksi tulis. Dipanggil SETELAH langkah 1-4 (rate limit, MAC + baris + ikatan, pengguna
     * dimuat ulang, sesi penuh). Hak + SPPG + Policy + versi dihitung ulang (BotAksiTulisService::periksa); ditolak hak
     * -> token tidak dihabiskan dan layar asal dinetralkan; keadaan berubah -> token basi ditutup + "data sudah berubah";
     * lalu konsumsi atomik (grup Ya/Batal: hanya satu yang pernah lolos), baru satu pemanggilan service.
     */
    private function aksiTulis(?string $id, User $user, BotAksi $aksi, int $fromId, int $chatId, ?int $messageId): void
    {
        $tolak = $this->tulis->periksa($user, $aksi);

        if (in_array($tolak, [BotAksiTulisService::BERUBAH, BotAksiTulisService::DI_LUAR_JENDELA, BotAksiTulisService::SEDANG_DIKIRIM], true)) {
            if ($this->aksi->konsumsi($aksi)) {
                $this->aksi->selesai($aksi, $tolak === BotAksiTulisService::BERUBAH ? 'konflik_versi' : $tolak);
            }

            $this->jawab($id, $tolak === BotAksiTulisService::BERUBAH ? 'Data sudah berubah.' : 'Aksi tidak lagi tersedia.', alert: true);
            $this->tampilkan($chatId, $messageId, $this->tulis->layarTolakKeadaan($user, $aksi, $chatId, $tolak));
            $this->catat($aksi, $user, $tolak);

            return;
        }

        if ($tolak !== null) {
            // Ditolak hak/data hilang: token dan saudara satu layar (Ya/Batal) ikut kedaluwarsa (bukan dipakai); tanpa efek.
            $this->aksi->kedaluwarsakan($aksi);
            $this->jawab($id, $tolak === BotAksiTulisService::TIDAK_ADA ? 'Data tidak ditemukan atau sudah tidak berlaku.' : self::TIDAK_BERHAK, alert: true);
            $this->netralkan($chatId, $messageId, self::LAYAR_DITUTUP_HAK);
            $this->catat($aksi, $user, $tolak);

            return;
        }

        if (! $this->aksi->konsumsi($aksi)) {
            RateLimiter::hit('tg:cbgagal:'.$fromId, 60);
            $this->jawab($id, self::TIDAK_BERLAKU);

            return;
        }

        $dijawab = false;
        $hasil = null;

        try {
            if (BotAksiTulisService::tahapAksi($aksi)) {
                $layar = $this->tulis->konfirmasi($user, $aksi, $chatId);
                $hasil = 'konfirmasi';
            } elseif ($aksi->jenis === BotAksi::JENIS_BATAL_AKSI) {
                $layar = $this->tulis->layarBatal($user, $aksi, $chatId);
                $hasil = 'batal';
            } else {
                ['hasil' => $hasil, 'layar' => $layar] = $this->tulis->jalankan($user, $aksi, $chatId);
            }

            $this->aksi->selesai($aksi, $hasil);
            $this->jawab($id, $hasil === 'batal' ? 'Dibatalkan.' : null);
            $dijawab = true;
            $this->tampilkan($chatId, $messageId, $layar);
            $this->catat($aksi, $user, $hasil);
        } catch (\Throwable $e) {
            BotGalat::catat($e, 'aksi_tulis');
            // Efek bisnis sudah terjadi (mis. galat saat menampilkan hasil setelah commit): catat hasil sebenarnya dan
            // jangan menyuruh mencoba lagi secara buta (kirim rekap/notifikasi perjalanan tidak dapat ditarik).
            $berefek = in_array($hasil, BotAksiTulisService::HASIL_BEREFEK, true);
            $this->aksi->selesai($aksi, $berefek ? $hasil : 'gagal');

            if (! $dijawab) {
                $this->jawab($id);
            }

            $this->kirim($chatId, new BotLayar($berefek ? BotAksiTulisService::PESAN_SUDAH_DIPROSES : 'Terjadi kesalahan. Ketik /menu untuk memulai lagi.', null, false));
            $this->catat($aksi, $user, $berefek ? $hasil : 'gagal');
        }
    }

    // ------------------------------------------------------------------ aksi

    private function navigasi(BotAksi $aksi, int $chatId, ?int $messageId, BotLayar $layar): void
    {
        $this->tampilkan($chatId, $messageId, $layar);
        $this->aksi->selesai($aksi, 'ok');
    }

    private function setujui(User $user, BotAksi $aksi, int $chatId, ?int $messageId): void
    {
        $hasil = $this->keputusan->putuskan($user, $aksi, 'disetujui', null, $chatId);
        $this->aksi->selesai($aksi, $hasil['hasil']);
        $this->tampilkan($chatId, $messageId, $hasil['layar']);
        $this->catat($aksi, $user, $hasil['hasil']);
    }

    private function mintaCatatan(User $user, BotAksi $aksi, int $chatId, ?int $messageId): void
    {
        [$min, $maks] = BotKeputusanService::batasCatatan($aksi->subjek_tipe);

        // Hapus tombol pada layar tinjau; keputusan menunggu alasan.
        if ($messageId !== null) {
            try {
                $this->bot->editMessageReplyMarkup($chatId, $messageId, null);
            } catch (TelegramApiException $e) {
                Log::warning('bot.hapus_tombol_gagal', ['kode' => $e->errorCode]);
            }
        }

        try {
            $terkirim = $this->bot->sendMessage($chatId, "Ketik alasan penolakan ({$min}-{$maks} karakter) sebagai BALASAN pesan ini, atau ketik /batal.", [
                'reply_markup' => TelegramBotService::forceReply('Alasan penolakan'),
            ]);
        } catch (TelegramApiException $e) {
            Log::warning('bot.prompt_catatan_gagal', ['kode' => $e->errorCode]);
            $this->aksi->batalkanCatatan((int) $aksi->telegram_id);

            return;
        }

        $promptId = $terkirim['message_id'] ?? null;

        if (! is_int($promptId)) {
            $this->aksi->batalkanCatatan((int) $aksi->telegram_id);

            return;
        }

        $this->aksi->simpanPrompt($aksi, $promptId);
        $this->catat($aksi, $user, 'minta_catatan');
    }

    // ------------------------------------------------------------------ util

    private function cariUser(int $telegramId): ?User
    {
        return User::query()->withoutGlobalScopes()->where('telegram_id', $telegramId)->first();
    }

    private function jawab(?string $id, ?string $teks = null, bool $alert = false): void
    {
        if ($id === null) {
            return;
        }

        try {
            $this->bot->answerCallbackQuery($id, $teks, $alert);
        } catch (TelegramApiException $e) {
            Log::warning('telegram.callback_gagal', ['galat' => $e->getMessage()]);
        }
    }

    /**
     * Ubah pesan asal bila ada. "message is not modified" (isi identik, mis. Segarkan tanpa perubahan) diabaikan:
     * callback sudah dijawab, JANGAN kirim pesan baru. Galat edit lain (pesan terlalu lama/tidak ditemukan) -> pesan baru.
     * Log hanya memuat kode galat, tidak pernah isi layar. Mengembalikan cara layar ditampilkan (TAMPIL_*).
     */
    private function tampilkan(int $chatId, ?int $messageId, BotLayar $layar): string
    {
        if ($messageId !== null) {
            try {
                $this->bot->editMessageText($chatId, $messageId, $layar->teks, array_filter([
                    'parse_mode' => $layar->html ? TelegramBotService::PARSE_HTML : null,
                    'reply_markup' => $layar->markup,
                ]));

                return self::TAMPIL_EDIT;
            } catch (TelegramApiException $e) {
                if ($e->isMessageNotModified()) {
                    Log::info('bot.edit_tidak_berubah', ['kode' => $e->errorCode]);

                    return self::TAMPIL_TIDAK_BERUBAH;
                }

                Log::warning('bot.edit_gagal', ['kode' => $e->errorCode]);
            }
        }

        $this->kirim($chatId, $layar);

        return self::TAMPIL_KIRIM;
    }

    private function kirim(int $chatId, BotLayar $layar): void
    {
        try {
            $this->bot->sendMessage($chatId, $layar->teks, array_filter([
                'parse_mode' => $layar->html ? TelegramBotService::PARSE_HTML : null,
                'reply_markup' => $layar->markup,
                'protect_content' => $layar->lindungi ?: null,
            ]));
        } catch (TelegramApiException $e) {
            Log::warning('telegram.balasan_gagal', ['kode' => $e->errorCode, 'galat' => $e->getMessage()]);
        }
    }

    /** @return array{inline_keyboard: list<list<array<string, mixed>>>}|null */
    private function bukaAplikasi(): ?array
    {
        return TelegramBotService::inlineKeyboard([TelegramBotService::webAppButton(TelegramUpdateHandler::LABEL_MASUK, $this->bot->miniAppUrl())]);
    }

    /** Log aman: jenis, hasil, user_id, subjek, chat id ter-hash. TANPA catatan, nama, atau data pribadi. */
    private function catat(BotAksi $aksi, User $user, string $hasil): void
    {
        Log::info('bot.aksi', [
            'jenis' => $aksi->jenis,
            'hasil' => $hasil,
            'user_id' => (int) $user->getKey(),
            'subjek' => $aksi->subjek_tipe === null ? null : $aksi->subjek_tipe.':'.$aksi->subjek_id,
            'sppg_id' => $aksi->sppg_id,
            'chat' => substr(hash_hmac('sha256', 'chat|'.$aksi->chat_id, (string) config('app.key')), 0, 16),
        ]);
    }
}
