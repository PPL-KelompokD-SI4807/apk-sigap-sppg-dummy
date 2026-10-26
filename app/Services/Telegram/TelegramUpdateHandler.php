<?php

namespace App\Services\Telegram;

use App\Enums\TipeUndangan;
use App\Jobs\ProcessAiMessage;
use App\Models\Scopes\SppgScope;
use App\Models\Sppg;
use App\Models\TelegramUpdate;
use App\Models\TitikLayanan;
use App\Models\User;
use App\Services\Auth\SesiService;
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
 * - Data pengirim yang tidak terdaftar TIDAK disimpan: payload update diringkas setelah diproses.
 */
class TelegramUpdateHandler
{
    /** Balasan bila pengguna belum punya sesi login penuh (Agen AI ditolak). */
    public const PERLU_MASUK = 'Masuk lewat Mini App terlebih dahulu (username/no telepon dan password) sebelum memakai Agen AI di chat ini. Tekan tombol "Buka SiGAP" di bawah.';

    public function __construct(
        private readonly TelegramBotService $bot,
        private readonly InvitationService $invitations,
        private readonly SesiService $sesi,
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

        $untukAi = $text !== null || isset($message['photo']) || isset($message['voice']);

        if ($untukAi && ! $this->sesi->punyaSesiPenuh($user)) {
            $this->reply($chatId, self::PERLU_MASUK, $this->openAppKeyboard());
            $this->finish($update, 'ai_tanpa_sesi');

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
            $markup = $user !== null && $user->canAuthenticate() ? $this->openAppKeyboard() : null;
            $this->reply($chatId, $e->getMessage(), $markup);

            return;
        } catch (Throwable $e) {
            report($e);
            $this->reply($chatId, 'Maaf, undangan belum dapat diproses. Coba lagi beberapa saat lagi.');

            return;
        }

        if ($consumed->tipe === TipeUndangan::Pengguna && $consumed->user !== null) {
            $this->greet($chatId, $consumed->user, 'Akun Anda berhasil terhubung');

            return;
        }

        $titik = $consumed->titikLayanan;
        $this->reply($chatId, sprintf(
            "Terima kasih. Telegram Anda kini terhubung sebagai PIC <b>%s</b>.\nAnda akan menerima notifikasi perjalanan mobil SPPG di chat ini.",
            TelegramBotService::escapeHtml($titik?->nama ?? 'titik layanan'),
        ), html: true);
    }

    private function greet(int $chatId, User $user, string $opening): void
    {
        $sppg = $user->sppg_id === null
            ? 'semua SPPG'
            : (string) (Sppg::query()->withoutGlobalScopes()->whereKey($user->sppg_id)->value('nama') ?? 'SPPG Anda');
        $unit = $user->unit !== null ? ' unit '.$user->unit->label() : '';

        $html = sprintf(
            "%s, <b>%s</b>!\nPeran: <b>%s%s</b> di <b>%s</b>.\n\nBuka Mini App lewat tombol di bawah, atau ketik/kirim foto/pesan suara di sini untuk dibantu Agen AI.",
            TelegramBotService::escapeHtml($opening),
            TelegramBotService::escapeHtml($user->name),
            TelegramBotService::escapeHtml($user->role->label()),
            TelegramBotService::escapeHtml($unit),
            TelegramBotService::escapeHtml($sppg),
        );

        $this->reply($chatId, $html, $this->openAppKeyboard(), html: true);
    }

    private function help(int $chatId, ?User $user): void
    {
        $text = $user !== null && $user->canAuthenticate()
            ? "Cara memakai SiGAP SPPG:\n- Tombol \"Buka SiGAP\" membuka Mini App.\n- Ketik permintaan biasa, mis. \"cari titik layanan SD\".\n- Kirim foto nota/surat jalan untuk dibuatkan draf.\n- Setiap draf wajib diperiksa dan disimpan di Mini App."
            : 'Bot SiGAP SPPG hanya untuk pengguna terdaftar. Minta tautan undangan kepada Admin SPPG.';

        $this->reply($chatId, $text, $user !== null && $user->canAuthenticate() ? $this->openAppKeyboard() : null);
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
        if (is_string($query['id'] ?? null)) {
            try {
                $this->bot->answerCallbackQuery($query['id']);
            } catch (TelegramApiException $e) {
                Log::warning('telegram.callback_gagal', ['galat' => $e->getMessage()]);
            }
        }
    }

    private function findUser(int $telegramId): ?User
    {
        return User::query()->withoutGlobalScopes()->where('telegram_id', $telegramId)->first();
    }

    private function openAppKeyboard(): ?array
    {
        return TelegramBotService::inlineKeyboard([TelegramBotService::webAppButton('Buka SiGAP', $this->bot->miniAppUrl())]);
    }

    private function reply(int $chatId, string $text, ?array $markup = null, bool $html = false): void
    {
        try {
            $this->bot->sendMessage($chatId, $text, array_filter([
                'parse_mode' => $html ? TelegramBotService::PARSE_HTML : null,
                'reply_markup' => $markup,
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
