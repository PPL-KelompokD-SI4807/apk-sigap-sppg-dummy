<?php

namespace App\Services\Telegram;

use App\Enums\Role;
use App\Enums\TipeUndangan;
use App\Jobs\ProcessAiMessage;
use App\Models\Scopes\SppgScope;
use App\Models\Sppg;
use App\Models\TelegramUpdate;
use App\Models\TitikLayanan;
use App\Models\User;
use App\Services\Auth\SesiService;
use App\Services\Telegram\Bot\BotCallbackHandler;
use App\Services\Telegram\Bot\BotGalat;
use App\Services\Telegram\Bot\BotLayarRegistry;
use App\Services\Telegram\Bot\BotNavHasil;
use App\Services\Telegram\Bot\BotNavigasi;
use App\Services\Telegram\Bot\Layar\KonteksLayar;
use App\Services\Users\InvalidInvitationException;
use App\Services\Users\InvitationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Memproses satu pembaruan webhook (dijalankan job ProcessTelegramUpdate).
 *
 * - Hanya chat pribadi; pesan grup/kanal dan pengirim bot diabaikan tanpa balasan.
 * - /start inv_<token>: onboarding lewat InvitationService::consumeAny (pengguna atau PIC).
 * - /start tanpa token: pengguna terdaftar aktif -> tombol Mini App; lainnya -> minta undangan.
 * - Teks/foto/suara dari pengguna terdaftar aktif DENGAN sesi login penuh (R-03) -> job
 *   ProcessAiMessage (Agen AI). Tanpa sesi penuh (belum masuk, logout, sesi dicabut, password
 *   di-reset) -> arahan masuk lewat Mini App; Agen AI tidak dipanggil.
 * - R-02: /menu (tombol per peran), /batal, callback_query (BotCallbackHandler), dan balasan catatan penolakan
 *   (tidak pernah ke Agen AI). Semua jalur yang membuka data/menu wajib punyaSesiPenuh; tanpa sesi: petunjuk masuk saja.
 * - R-02 v2: perintah pintasan (/jadwal, /stok, ... BotLayarRegistry::PERINTAH) membuka layar yang sama dengan
 *   tombolnya lewat BotNavigasi (sesi penuh + RBAC + SPPG dicek saat dijalankan). Pesan berisi menu/data dikirim
 *   dengan protect_content.
 * - Data pengirim yang tidak terdaftar TIDAK disimpan: payload update diringkas setelah diproses.
 */
class TelegramUpdateHandler
{
    /** Balasan bila pengguna belum punya sesi login penuh (Agen AI ditolak). */
    public const PERLU_MASUK = 'Masuk lewat Mini App terlebih dahulu (username/no telepon dan password) sebelum memakai bot ini. Tekan tombol "Masuk lewat Mini App" di bawah.';

    /** Label satu-satunya tombol bot untuk pengguna tanpa sesi login penuh. */
    public const LABEL_MASUK = 'Masuk lewat Mini App';

    /** Salam netral /start tanpa sesi penuh: tanpa nama pengguna, peran, atau SPPG. */
    public const SALAM_TANPA_SESI = 'Selamat datang di SiGAP SPPG. Masuk lewat Mini App untuk melanjutkan.';

    /** Jawaban seragam bila perintah pintasan tidak boleh/tidak bisa dibuka untuk peran saat ini. */
    public const PERINTAH_TIDAK_TERSEDIA = 'Perintah ini tidak tersedia untuk peran Anda.';

    public function __construct(
        private readonly TelegramBotService $bot,
        private readonly InvitationService $invitations,
        private readonly SesiService $sesi,
        private readonly BotCallbackHandler $callbacks,
        private readonly BotNavigasi $nav,
    ) {}

    public function handle(TelegramUpdate $update): void
    {
        $payload = (array) $update->payload;

        if (isset($payload['callback_query']) && is_array($payload['callback_query'])) {
            $this->callback($payload['callback_query']);
            $this->finish($update, 'callback_query');

            return;
        }

        $message = $payload['message'] ?? null;

        if (! is_array($message) || ($message['chat']['type'] ?? null) !== 'private') {
            $this->finish($update, is_array($message) ? 'non_private' : 'lainnya');

            return;
        }

        $from = $message['from'] ?? null;
        $chatId = $message['chat']['id'] ?? null;

        if (! is_array($from) || ($from['is_bot'] ?? false) === true || ! is_int($from['id'] ?? null) || ! is_int($chatId)) {
            $this->finish($update, 'tidak_valid');

            return;
        }

        $text = is_string($message['text'] ?? null) ? trim($message['text']) : null;

        // Batas perintah per pengirim (anti-banjir): lewat batas -> diabaikan tanpa balasan.
        if ($text !== null && preg_match('/^\/(start|bantuan|help|menu|'.self::polaPintasan().')(?:@[A-Za-z0-9_]+)?(?:\s|$)/i', $text) === 1
            && ! RateLimiter::attempt('tg:cmd:'.$from['id'], (int) config('telegram.perintah_rate', 20), fn () => true, 60)) {
            $this->finish($update, 'dibatasi');

            return;
        }

        if ($text !== null && preg_match('/^\/start(?:@[A-Za-z0-9_]+)?(?:\s+(\S{1,128}))?$/', $text, $m) === 1) {
            $this->start($chatId, $from, $m[1] ?? null);
            $this->finish($update, 'start');

            return;
        }

        if ($text !== null && preg_match('/^\/(bantuan|help)(?:@[A-Za-z0-9_]+)?$/i', $text) === 1) {
            $this->help($chatId, $this->findUser($from['id']));
            $this->finish($update, 'bantuan');

            return;
        }

        $user = $this->findUser($from['id']);

        if ($user === null || ! $user->canAuthenticate()) {
            $this->unregistered($chatId, $user);
            $this->finish($update, 'tidak_terdaftar');

            return;
        }

        $perintah = $text !== null && preg_match('/^\/(menu|batal|'.self::polaPintasan().')(?:@[A-Za-z0-9_]+)?$/i', $text, $pm) === 1 ? strtolower($pm[1]) : null;
        $untukAi = $text !== null || isset($message['photo']) || isset($message['voice']);

        if (($untukAi || $perintah !== null) && ! $this->sesi->punyaSesiPenuh($user)) {
            // Sesi dicabut di tengah alur: jendela balasan alasan penolakan ikut ditutup.
            $this->callbacks->tutupProsesTanpaSesi($user);
            $this->reply($chatId, self::PERLU_MASUK, $this->masukKeyboard());
            $this->finish($update, $perintah !== null ? 'bot_tanpa_sesi' : 'ai_tanpa_sesi');

            return;
        }

        if ($perintah === 'menu') {
            $this->bukaLayar($user, $chatId, BotLayarRegistry::BERANDA);
            $this->finish($update, 'menu');

            return;
        }

        if ($perintah !== null && isset(BotLayarRegistry::PERINTAH[$perintah])) {
            $this->bukaLayar($user, $chatId, BotLayarRegistry::PERINTAH[$perintah]);
            $this->finish($update, 'pintasan');

            return;
        }

        if ($perintah === 'batal') {
            $this->callbacks->batal($user, $chatId);
            $this->finish($update, 'batal');

            return;
        }

        // Balasan alasan penolakan (R-02): milik alur bot, TIDAK PERNAH diteruskan ke Agen AI.
        if (isset($message['reply_to_message']) && $this->callbacks->balasanCatatan($user, $chatId, $message)) {
            $this->finish($update, 'catatan');

            return;
        }

        if ($untukAi) {
            // Agen AI di job terpisah (dapat lama). Payload diringkas oleh job tersebut.
            $update->forceFill(['processed_at' => now()])->save();
            ProcessAiMessage::dispatch($update->id, $user->getKey());

            return;
        }

        $this->reply($chatId, 'Jenis pesan ini belum didukung. Kirim teks, foto nota, atau pesan suara.');
        $this->finish($update, 'tidak_didukung');
    }

    /** @param array<string, mixed> $from */
    private function start(int $chatId, array $from, ?string $param): void
    {
        if ($param !== null && str_starts_with($param, InvitationService::DEEP_LINK_PREFIX)) {
            $this->onboard($chatId, $from, $param);

            return;
        }

        $user = $this->findUser($from['id']);

        if ($user !== null && $user->canAuthenticate()) {
            $this->greet($chatId, $user, 'Selamat datang kembali');

            return;
        }

        $this->unregistered($chatId, $user, force: true);
    }

    /** @param array<string, mixed> $from */
    private function onboard(int $chatId, array $from, string $token): void
    {
        $telegramUser = [
            'id' => $from['id'],
            'first_name' => is_string($from['first_name'] ?? null) ? $from['first_name'] : null,
            'last_name' => is_string($from['last_name'] ?? null) ? $from['last_name'] : null,
            'username' => is_string($from['username'] ?? null) ? $from['username'] : null,
            'chat_id' => $chatId,
        ];

        try {
            $consumed = $this->invitations->consumeAny($token, $telegramUser);
        } catch (InvalidInvitationException $e) {
            $user = $e->reason === InvalidInvitationException::TELEGRAM_SUDAH_TERDAFTAR ? $this->findUser($from['id']) : null;
            $markup = $user !== null && $user->canAuthenticate()
                ? ($this->sesi->punyaSesiPenuh($user) ? $this->openAppKeyboard() : $this->masukKeyboard())
                : null;
            $this->reply($chatId, $e->getMessage(), $markup);

            return;
        } catch (Throwable $e) {
            report($e);
            $this->reply($chatId, 'Maaf, undangan belum dapat diproses. Coba lagi beberapa saat lagi.');

            return;
        }

        if ($consumed->tipe === TipeUndangan::Pengguna && $consumed->user !== null) {
            $this->greet($chatId, $consumed->user, 'Akun Anda berhasil terhubung', baru: true);

            return;
        }

        $titik = $consumed->titikLayanan;
        $this->reply($chatId, sprintf(
            "Terima kasih. Telegram Anda kini terhubung sebagai PIC <b>%s</b>.\nAnda akan menerima notifikasi perjalanan mobil SPPG di chat ini.",
            TelegramBotService::escapeHtml($titik?->nama ?? 'titik layanan'),
        ), html: true);
    }

    private function greet(int $chatId, User $user, string $opening, bool $baru = false): void
    {
        // R-02: tanpa sesi login penuh hanya salam netral (tanpa nama/peran/SPPG) + satu tombol masuk.
        if (! $this->sesi->punyaSesiPenuh($user)) {
            $this->reply(
                $chatId,
                $baru ? 'Selamat datang di SiGAP SPPG. Akun Anda berhasil terhubung. Masuk lewat Mini App untuk melanjutkan.' : self::SALAM_TANPA_SESI,
                $this->masukKeyboard(),
            );

            return;
        }

        // R06a-S-07: investor (sppg_id null) tidak lintas SPPG; SPPG aktif dipilih lewat tombol Pilih SPPG.
        $sppg = match (true) {
            $user->role === Role::Investor => 'SPPG milik Anda',
            $user->sppg_id === null => 'semua SPPG',
            default => (string) (Sppg::query()->withoutGlobalScopes()->whereKey($user->sppg_id)->value('nama') ?? 'SPPG Anda'),
        };
        $unit = $user->unit !== null ? ' unit '.$user->unit->label() : '';

        $html = sprintf(
            '%s, <b>%s</b>!'."\n".'Peran: <b>%s%s</b> di <b>%s</b>.',
            TelegramBotService::escapeHtml($opening),
            TelegramBotService::escapeHtml($user->name),
            TelegramBotService::escapeHtml($user->role->label()),
            TelegramBotService::escapeHtml($unit),
            TelegramBotService::escapeHtml($sppg),
        );

        // Sambutan + ringkasan hari ini + beranda (tombol per peran) dalam satu pesan; hak dicek BotNavigasi.
        $this->bukaLayar($user, $chatId, BotLayarRegistry::BERANDA, $html);
    }

    /**
     * Buka layar baca (beranda, perintah pintasan, bantuan) sebagai pesan baru terlindungi. Pemanggil sudah
     * memastikan pengguna aktif + sesi penuh; BotNavigasi memeriksa ulang sesi, RBAC, dan SPPG. `$awal` = HTML
     * sambutan (sudah di-escape) yang ditaruh di atas layar; bila layar tidak dapat dibuka, sambutan saja + tombol Mini App.
     */
    private function bukaLayar(User $user, int $chatId, string $layarId, ?string $awal = null): void
    {
        try {
            $hasil = $this->nav->buka($user, $layarId, null, new KonteksLayar($chatId, (int) $user->telegram_id));
        } catch (Throwable $e) {
            BotGalat::catat($e, 'pintasan');
            $this->reply($chatId, 'Terjadi kesalahan. Ketik /menu untuk memulai lagi.');

            return;
        }

        match ($hasil->status) {
            BotNavHasil::OK => $this->reply($chatId, ($awal === null ? '' : $awal."\n\n").$hasil->layar->teks, $hasil->layar->markup, html: $hasil->layar->html, lindungi: true),
            BotNavHasil::TANPA_SESI => $this->reply($chatId, self::PERLU_MASUK, $this->masukKeyboard()),
            BotNavHasil::PILIH_SPPG => $this->reply($chatId, BotCallbackHandler::PILIH_SPPG),
            BotNavHasil::TERLALU_CEPAT => $this->reply($chatId, 'Terlalu cepat. Coba lagi sebentar.'),
            default => $awal !== null
                ? $this->reply($chatId, $awal, $this->openAppKeyboard(), html: true, lindungi: true)
                : $this->reply($chatId, self::PERINTAH_TIDAK_TERSEDIA),
        };
    }

    /** Alternatif regex perintah pintasan (nama perintah tetap dari kode, aman untuk regex). */
    private static function polaPintasan(): string
    {
        return implode('|', array_keys(BotLayarRegistry::PERINTAH));
    }

    private function help(int $chatId, ?User $user): void
    {
        $aktif = $user !== null && $user->canAuthenticate();

        // R-02: tanpa sesi login penuh hanya petunjuk masuk + tombol masuk (tanpa bantuan fitur) untuk SEMUA peran.
        if ($aktif && ! $this->sesi->punyaSesiPenuh($user)) {
            $this->reply($chatId, self::PERLU_MASUK, $this->masukKeyboard());

            return;
        }

        if ($aktif) {
            $this->bukaLayar($user, $chatId, BotLayarRegistry::BANTUAN);

            return;
        }

        $this->reply($chatId, 'Bot SiGAP SPPG hanya untuk pengguna terdaftar. Minta tautan undangan kepada Admin SPPG.');
    }

    private function unregistered(int $chatId, ?User $user, bool $force = false): void
    {
        // Batasi balasan ke pengirim tak terdaftar (anti-spam), tanpa menyimpan datanya.
        if (! $force && ! RateLimiter::attempt('tg:unreg:'.hash('sha256', (string) $chatId), 3, fn () => true, 600)) {
            return;
        }

        if ($user !== null) {
            $this->reply($chatId, 'Akun SiGAP SPPG Anda nonaktif. Hubungi Admin SPPG.');

            return;
        }

        $isPic = TitikLayanan::query()->withoutGlobalScope(SppgScope::class)->where('pic_telegram_chat_id', $chatId)->exists();

        $this->reply($chatId, $isPic
            ? 'Chat ini terdaftar sebagai PIC titik layanan. Bot hanya mengirim notifikasi perjalanan mobil SPPG ke sini.'
            : 'Akun Telegram Anda belum terdaftar di SiGAP SPPG. Minta tautan undangan kepada Admin SPPG, lalu buka tautan tersebut di Telegram.');
    }

    /** @param array<string, mixed> $query */
    private function callback(array $query): void
    {
        $this->callbacks->handle($query);
    }

    private function findUser(int $telegramId): ?User
    {
        return User::query()->withoutGlobalScopes()->where('telegram_id', $telegramId)->first();
    }

    /** Satu-satunya tombol untuk pengguna tanpa sesi login penuh. */
    private function masukKeyboard(): ?array
    {
        return TelegramBotService::inlineKeyboard([TelegramBotService::webAppButton(self::LABEL_MASUK, $this->bot->miniAppUrl())]);
    }

    private function openAppKeyboard(): ?array
    {
        return TelegramBotService::inlineKeyboard([TelegramBotService::webAppButton('Buka SiGAP', $this->bot->miniAppUrl())]);
    }

    private function reply(int $chatId, string $text, ?array $markup = null, bool $html = false, bool $lindungi = false): void
    {
        try {
            $this->bot->sendMessage($chatId, $text, array_filter([
                'parse_mode' => $html ? TelegramBotService::PARSE_HTML : null,
                'reply_markup' => $markup,
                'protect_content' => $lindungi ?: null,
            ]));
        } catch (TelegramApiException $e) {
            Log::warning('telegram.balasan_gagal', ['kode' => $e->errorCode, 'galat' => $e->getMessage()]);
        }
    }

    /** Tandai selesai dan ringkas payload (minimisasi data, UU PDP). */
    public static function finish(TelegramUpdate $update, string $jenis): void
    {
        $update->forceFill([
            'processed_at' => $update->processed_at ?? now(),
            'payload' => ['ringkas' => true, 'jenis' => $jenis],
        ])->save();
    }
}
