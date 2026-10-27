<?php

namespace App\Services\Telegram\Bot;

use App\Models\BotAksi;
use App\Models\User;
use App\Services\Auth\SesiService;
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
 *   2. MAC callback_data + muat baris + status/kedaluwarsa + ikatan from.id/chat.id/user_id;
 *   3. muat ulang pengguna dari basis data (aktif);
 *   4. SesiService::punyaSesiPenuh (tanpa sesi: petunjuk login, bukan data, token tidak dihabiskan);
 *   5. hak dihitung ulang (Rbac::canDecide + pivot + SPPG record + Policy) -> BotKeputusanService::periksa;
 *   6. konsumsi token atomik, lalu panggil service bisnis.
 * Isi callback_data tidak pernah menjadi sumber hak akses.
 */
class BotCallbackHandler
{
    public const TIDAK_BERLAKU = 'Tombol sudah tidak berlaku. Ketik /menu.';

    public const TIDAK_BERHAK = 'Anda tidak berhak melakukan aksi ini.';

    public function __construct(
        private readonly TelegramBotService $bot,
        private readonly BotAksiService $aksi,
        private readonly SesiService $sesi,
        private readonly BotKeputusanService $keputusan,
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

        // 2-3. MAC + baris + ikatan + pengguna dimuat ulang. Semua kegagalan dijawab seragam (tanpa oracle).
        $user = $this->cariUser($fromId);
        $aksi = $this->aksi->temukan($query['data'] ?? null, $fromId);

        if ($aksi === null || $user === null || ! $user->canAuthenticate() || ! $this->aksi->berlaku($aksi, $fromId, $chatId, $user)) {
            RateLimiter::hit('tg:cbgagal:'.$fromId, 60);
            $this->jawab($id, self::TIDAK_BERLAKU);

            return;
        }

        // 4. Sesi login penuh.
        if (! $this->sesi->punyaSesiPenuh($user)) {
            $this->aksi->batalkanCatatan($fromId);
            $this->jawab($id, 'Masuk lewat Mini App terlebih dahulu.');
            $this->kirim($chatId, new BotLayar(TelegramUpdateHandler::PERLU_MASUK, $this->bukaAplikasi(), false));
            $this->catat($aksi, $user, 'tanpa_sesi');

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
     * Log hanya memuat kode galat, tidak pernah isi layar.
     */
    private function tampilkan(int $chatId, ?int $messageId, BotLayar $layar): void
    {
        if ($messageId !== null) {
            try {
                $this->bot->editMessageText($chatId, $messageId, $layar->teks, array_filter([
                    'parse_mode' => $layar->html ? TelegramBotService::PARSE_HTML : null,
                    'reply_markup' => $layar->markup,
                ]));

                return;
            } catch (TelegramApiException $e) {
                if ($e->isMessageNotModified()) {
                    Log::info('bot.edit_tidak_berubah', ['kode' => $e->errorCode]);

                    return;
                }

                Log::warning('bot.edit_gagal', ['kode' => $e->errorCode]);
            }
        }

        $this->kirim($chatId, $layar);
    }

    private function kirim(int $chatId, BotLayar $layar): void
    {
        try {
            $this->bot->sendMessage($chatId, $layar->teks, array_filter([
                'parse_mode' => $layar->html ? TelegramBotService::PARSE_HTML : null,
                'reply_markup' => $layar->markup,
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
