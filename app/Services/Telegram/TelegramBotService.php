<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Klien Bot API Telegram memakai Laravel HTTP Client (KT-04, tanpa SDK pihak ketiga).
 *
 * Keamanan:
 * - Token bot hanya dari config('telegram.bot_token') dan hanya berada di URL permintaan;
 *   semua exception dibungkus TelegramApiException dengan token disamarkan (tidak bocor ke log).
 * - Teks dinamis WAJIB di-escape: pakai escapeHtml() untuk parse_mode HTML, escapeMarkdownV2()
 *   untuk MarkdownV2, atau kirim tanpa parse_mode (teks polos, mis. jawaban model AI).
 * - Tombol web_app hanya dibuat untuk URL HTTPS (syarat Telegram); selain itu dilewati.
 * - Timeout + retry terbatas (hanya galat koneksi/5xx); 429 dikembalikan ke pemanggil (job) agar
 *   mematuhi retry_after.
 */
class TelegramBotService
{
    public const MAX_MESSAGE_LENGTH = 4096;

    public const PARSE_HTML = 'HTML';

    public const PARSE_MARKDOWN_V2 = 'MarkdownV2';

    public function isConfigured(): bool
    {
        $token = config('telegram.bot_token');

        return is_string($token) && $token !== '';
    }

    // ------------------------------------------------------------------ escape

    public static function escapeHtml(?string $text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Escape semua karakter khusus MarkdownV2 (spesifikasi Bot API "Formatting options"). */
    public static function escapeMarkdownV2(?string $text): string
    {
        return (string) preg_replace('/([_*\[\]()~`>#+\-=|{}.!\\\\])/u', '\\\\$1', (string) $text);
    }

    /** Potong teks agar muat satu pesan Telegram (aman multibyte). */
    public static function limit(string $text, int $max = self::MAX_MESSAGE_LENGTH): string
    {
        return mb_strlen($text) <= $max ? $text : rtrim(mb_substr($text, 0, $max - 1)).'…';
    }

    // ------------------------------------------------------------------ keyboard

    /** URL Mini App (+ query/hash opsional). */
    public function miniAppUrl(array $query = [], ?string $hashPath = null): string
    {
        $url = (string) config('telegram.miniapp_url');

        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($query);
        }

        if ($hashPath !== null && $hashPath !== '') {
            $url .= '#'.(str_starts_with($hashPath, '/') ? $hashPath : '/'.$hashPath);
        }

        return $url;
    }

    /** Satu tombol web_app; null bila URL bukan HTTPS (Telegram menolak web_app non-HTTPS). */
    public static function webAppButton(string $label, string $url): ?array
    {
        if (! str_starts_with(strtolower($url), 'https://')) {
            return null;
        }

        return ['text' => mb_substr($label, 0, 64), 'web_app' => ['url' => $url]];
    }

    /**
     * reply_markup inline_keyboard dari daftar tombol (satu tombol per baris); null bila kosong.
     *
     * @param  list<array<string, mixed>|null>  $buttons
     */
    public static function inlineKeyboard(array $buttons): ?array
    {
        $rows = array_map(fn (array $b) => [$b], array_values(array_filter($buttons)));

        return $rows === [] ? null : ['inline_keyboard' => $rows];
    }

    // ------------------------------------------------------------------ metode Bot API

    /**
     * @param  array<string, mixed>  $options  parse_mode, reply_markup, disable_notification, protect_content (bool:
     *                                         cegah forward/simpan; hanya berlaku saat kirim, tidak untuk edit), ...
     * @return array<string, mixed> objek Message
     */
    public function sendMessage(int|string $chatId, string $text, array $options = []): array
    {
        $payload = array_filter([
            'chat_id' => $chatId,
            'text' => self::limit($text),
            'parse_mode' => $options['parse_mode'] ?? null,
            'reply_markup' => isset($options['reply_markup']) ? json_encode($options['reply_markup'], JSON_UNESCAPED_UNICODE) : null,
            'disable_notification' => $options['disable_notification'] ?? null,
            'protect_content' => ($options['protect_content'] ?? false) === true ? 'true' : null,
            'link_preview_options' => json_encode(['is_disabled' => true]),
        ], fn ($v) => $v !== null);

        return (array) $this->call('sendMessage', $payload);
    }

    /** Teks polos (tanpa parse_mode): aman untuk keluaran model/isi pengguna. */
    public function sendText(int|string $chatId, string $text, ?array $replyMarkup = null): array
    {
        return $this->sendMessage($chatId, $text, array_filter(['reply_markup' => $replyMarkup]));
    }

    /** HTML: pemanggil bertanggung jawab meng-escape bagian dinamis dengan escapeHtml(). */
    public function sendHtml(int|string $chatId, string $html, ?array $replyMarkup = null): array
    {
        return $this->sendMessage($chatId, $html, array_filter(['parse_mode' => self::PARSE_HTML, 'reply_markup' => $replyMarkup]));
    }

    /** Pesan + satu tombol web_app (mis. "Buka SiGAP"). */
    public function sendWithWebAppButton(int|string $chatId, string $text, string $label, string $url, ?string $parseMode = null): array
    {
        return $this->sendMessage($chatId, $text, array_filter([
            'parse_mode' => $parseMode,
            'reply_markup' => self::inlineKeyboard([self::webAppButton($label, $url)]),
        ]));
    }

    /**
     * Ubah teks (+ keyboard inline) pesan bot yang sudah terkirim. $options: parse_mode, reply_markup
     * (null = hapus keyboard, array = keyboard baru). Melempar TelegramApiException bila ditolak Telegram
     * (mis. pesan terlalu lama/tidak berubah); pemanggil memutuskan fallback.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>|bool
     */
    public function editMessageText(int|string $chatId, int $messageId, string $text, array $options = []): array|bool
    {
        $payload = array_filter([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => self::limit($text),
            'parse_mode' => $options['parse_mode'] ?? null,
            // Tanpa keyboard baru, keyboard lama dihapus (keyboard kosong eksplisit).
            'reply_markup' => json_encode($options['reply_markup'] ?? ['inline_keyboard' => []], JSON_UNESCAPED_UNICODE),
            'link_preview_options' => json_encode(['is_disabled' => true]),
        ], fn ($v) => $v !== null);

        return $this->call('editMessageText', $payload);
    }

    /** Ganti/hapus keyboard inline pesan tanpa mengubah teksnya ($replyMarkup null = hapus). */
    public function editMessageReplyMarkup(int|string $chatId, int $messageId, ?array $replyMarkup = null): array|bool
    {
        return $this->call('editMessageReplyMarkup', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => json_encode($replyMarkup ?? ['inline_keyboard' => []], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** reply_markup ForceReply: klien Telegram membuka balasan ke pesan ini (placeholder maks. 64 karakter). */
    public static function forceReply(?string $placeholder = null): array
    {
        return array_filter([
            'force_reply' => true,
            'input_field_placeholder' => $placeholder === null ? null : mb_substr($placeholder, 0, 64),
            'selective' => true,
        ], fn ($v) => $v !== null);
    }

    public function sendChatAction(int|string $chatId, string $action = 'typing'): void
    {
        $this->call('sendChatAction', ['chat_id' => $chatId, 'action' => $action]);
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null, bool $alert = false): void
    {
        $this->call('answerCallbackQuery', array_filter([
            'callback_query_id' => $callbackQueryId,
            'text' => $text === null ? null : mb_substr($text, 0, 200),
            'show_alert' => $alert && $text !== null ? 'true' : null,
        ], fn ($v) => $v !== null));
    }

    /** @return array{file_id?: string, file_size?: int, file_path?: string} */
    public function getFile(string $fileId): array
    {
        return (array) $this->call('getFile', ['file_id' => $fileId]);
    }

    /**
     * Unduh berkas (getFile + endpoint file). Ditolak bila melebihi $maxBytes.
     */
    public function downloadFile(string $fileId, int $maxBytes): string
    {
        $file = $this->getFile($fileId);
        $path = $file['file_path'] ?? null;

        if (! is_string($path) || $path === '' || str_contains($path, '..')) {
            throw new TelegramApiException('Berkas Telegram tidak tersedia.');
        }

        if (isset($file['file_size']) && (int) $file['file_size'] > $maxBytes) {
            throw new TelegramApiException('Berkas terlalu besar.', 413);
        }

        $url = rtrim((string) config('telegram.api_base_url'), '/').'/file/bot'.$this->token().'/'.ltrim($path, '/');

        try {
            $response = $this->http()->get($url);
        } catch (Throwable $e) {
            throw $this->sanitize($e);
        }

        if (! $response->successful()) {
            throw new TelegramApiException('Unduh berkas Telegram gagal.', $response->status());
        }

        $body = $response->body();

        if (strlen($body) > $maxBytes) {
            throw new TelegramApiException('Berkas terlalu besar.', 413);
        }

        return $body;
    }

    /**
     * @param  list<string>  $allowedUpdates
     */
    public function setWebhook(string $url, string $secret, array $allowedUpdates, bool $dropPending = false): bool
    {
        return (bool) $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => json_encode(array_values($allowedUpdates)),
            'drop_pending_updates' => $dropPending ? 'true' : 'false',
            'max_connections' => 20,
        ]);
    }

    public function deleteWebhook(bool $dropPending = false): bool
    {
        return (bool) $this->call('deleteWebhook', ['drop_pending_updates' => $dropPending ? 'true' : 'false']);
    }

    /** @return array<string, mixed> */
    public function getWebhookInfo(): array
    {
        return (array) $this->call('getWebhookInfo', []);
    }

    /** Tombol menu bot bertipe web_app (membuka Mini App). Null chat = default semua chat. */
    public function setChatMenuButton(string $text, string $url, int|string|null $chatId = null): bool
    {
        return (bool) $this->call('setChatMenuButton', array_filter([
            'chat_id' => $chatId,
            'menu_button' => json_encode(['type' => 'web_app', 'text' => mb_substr($text, 0, 64), 'web_app' => ['url' => $url]]),
        ], fn ($v) => $v !== null));
    }

    /**
     * Tombol menu bot bertipe `commands` (menampilkan daftar perintah setMyCommands). Null chat = default semua chat.
     * R-02 keputusan #3: menggantikan tombol web_app; Mini App dibuka lewat /start dan tombol "Buka Mini App".
     */
    public function setChatMenuButtonCommands(int|string|null $chatId = null): bool
    {
        return (bool) $this->call('setChatMenuButton', array_filter([
            'chat_id' => $chatId,
            'menu_button' => json_encode(['type' => 'commands']),
        ], fn ($v) => $v !== null));
    }

    /**
     * Daftar perintah GLOBAL bot (scope default, tanpa per-pengguna). Perintah: huruf kecil/angka/_ 1-32 karakter;
     * deskripsi 1-256 karakter. Hanya dipanggil dari CLI (sigap:telegram-set-webhook), tidak pernah dari pengguna.
     *
     * @param  array<string, string>  $perintah  perintah => deskripsi
     */
    public function setMyCommands(array $perintah, ?string $bahasa = null): bool
    {
        $daftar = [];

        foreach ($perintah as $nama => $deskripsi) {
            if (preg_match('/^[a-z0-9_]{1,32}$/D', (string) $nama) !== 1 || trim($deskripsi) === '') {
                throw new TelegramApiException('Daftar perintah bot tidak valid.');
            }

            $daftar[] = ['command' => (string) $nama, 'description' => mb_substr($deskripsi, 0, 256)];
        }

        if ($daftar === [] || count($daftar) > 100) {
            throw new TelegramApiException('Daftar perintah bot tidak valid.');
        }

        return (bool) $this->call('setMyCommands', array_filter([
            'commands' => json_encode($daftar, JSON_UNESCAPED_UNICODE),
            'language_code' => $bahasa,
        ], fn ($v) => $v !== null));
    }

    // ------------------------------------------------------------------ internal

    /**
     * @param  array<string, mixed>  $params
     */
    public function call(string $method, array $params): mixed
    {
        if (preg_match('/^[a-zA-Z]{3,40}$/', $method) !== 1) {
            throw new TelegramApiException('Metode Bot API tidak valid.');
        }

        $url = rtrim((string) config('telegram.api_base_url'), '/').'/bot'.$this->token().'/'.$method;

        try {
            $response = $this->http()->asForm()->post($url, $params);
        } catch (Throwable $e) {
            throw $this->sanitize($e);
        }

        return $this->result($response, $method);
    }

    private function result(Response $response, string $method): mixed
    {
        $json = $response->json();

        if ($response->successful() && is_array($json) && ($json['ok'] ?? false) === true) {
            return $json['result'] ?? true;
        }

        $code = is_array($json) && isset($json['error_code']) ? (int) $json['error_code'] : $response->status();
        $description = is_array($json) && is_string($json['description'] ?? null) ? mb_substr($json['description'], 0, 200) : 'tanpa keterangan';
        $retryAfter = is_array($json) && isset($json['parameters']['retry_after']) ? (int) $json['parameters']['retry_after'] : null;

        // Deskripsi galat Telegram bersifat tetap (bukan isi pesan pengguna); hanya dipakai untuk menentukan jenis.
        $jenis = $code === 400 && stripos($description, 'message is not modified') !== false
            ? TelegramApiException::JENIS_TIDAK_BERUBAH
            : null;

        throw new TelegramApiException("Bot API {$method} gagal ({$code}): ".$this->redact($description), $code, $retryAfter, $jenis);
    }

    private function http(): PendingRequest
    {
        return Http::timeout((int) config('telegram.http_timeout', 10))
            ->connectTimeout(5)
            ->acceptJson()
            ->retry(
                (int) config('telegram.http_retries', 2) + 1,
                (int) config('telegram.http_retry_sleep_ms', 300),
                fn (Throwable $e) => $e instanceof ConnectionException
                    || ($e instanceof RequestException && $e->response->serverError()),
                throw: false,
            );
    }

    private function token(): string
    {
        if (! $this->isConfigured()) {
            throw new TelegramApiException('Bot Telegram belum dikonfigurasi.');
        }

        return (string) config('telegram.bot_token');
    }

    private function sanitize(Throwable $e): TelegramApiException
    {
        if ($e instanceof TelegramApiException) {
            return $e;
        }

        $kind = $e instanceof ConnectionException ? 'koneksi' : class_basename($e);

        return new TelegramApiException('Bot API tidak dapat dihubungi ('.$kind.'): '.$this->redact(mb_substr($e->getMessage(), 0, 300)));
    }

    private function redact(string $text): string
    {
        $token = config('telegram.bot_token');

        if (is_string($token) && $token !== '') {
            $text = str_replace($token, '***', $text);
        }

        return (string) preg_replace('#/bot[0-9]+:[A-Za-z0-9_-]+#', '/bot***', $text);
    }
}
