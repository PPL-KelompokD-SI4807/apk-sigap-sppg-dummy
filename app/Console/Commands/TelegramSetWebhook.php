<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Console\Command;

/**
 * Daftarkan webhook bot (setWebhook + secret_token + allowed_updates) dan tombol menu Mini App.
 * Tidak pernah mencetak token bot maupun secret webhook. Lihat docs/api/telegram-bot.md.
 */
class TelegramSetWebhook extends Command
{
    protected $signature = 'sigap:telegram-set-webhook
        {--url= : URL HTTPS webhook (default APP_URL/api/v1/telegram/webhook)}
        {--drop-pending : Buang pembaruan yang menunggu}
        {--delete : Hapus webhook (kembali ke mode tanpa webhook)}
        {--info : Tampilkan status webhook saat ini}
        {--no-menu : Jangan mengatur tombol menu Mini App}';

    protected $description = 'Daftarkan webhook Telegram (secret_token) dan tombol menu web_app Mini App SiGAP SPPG.';

    public function handle(TelegramBotService $bot): int
    {
        if (! $bot->isConfigured()) {
            $this->error('TELEGRAM_BOT_TOKEN belum diisi.');

            return self::FAILURE;
        }

        try {
            if ($this->option('info')) {
                $info = $bot->getWebhookInfo();
                $this->line('URL: '.($info['url'] ?? '-'));
                $this->line('Pending: '.($info['pending_update_count'] ?? 0));
                $this->line('Galat terakhir: '.($info['last_error_message'] ?? '-'));

                return self::SUCCESS;
            }

            if ($this->option('delete')) {
                $bot->deleteWebhook((bool) $this->option('drop-pending'));
                $this->info('Webhook dihapus.');

                return self::SUCCESS;
            }

            $secret = (string) config('telegram.webhook_secret');

            if (preg_match('/^[A-Za-z0-9_-]{16,256}$/', $secret) !== 1) {
                $this->error('TELEGRAM_WEBHOOK_SECRET wajib diisi: 16-256 karakter A-Z, a-z, 0-9, _ atau - (mis. hasil `openssl rand -hex 32`).');

                return self::FAILURE;
            }

            $url = (string) ($this->option('url') ?: rtrim((string) config('app.url'), '/').'/api/v1/telegram/webhook');

            if (! str_starts_with(strtolower($url), 'https://') || filter_var($url, FILTER_VALIDATE_URL) === false) {
                $this->error('URL webhook harus HTTPS yang valid (Telegram menolak HTTP).');

                return self::FAILURE;
            }

            $bot->setWebhook($url, $secret, (array) config('telegram.allowed_updates', ['message', 'callback_query']), (bool) $this->option('drop-pending'));
            $this->info("Webhook terdaftar: {$url}");

            if (! $this->option('no-menu')) {
                $miniApp = $bot->miniAppUrl();

                if (str_starts_with(strtolower($miniApp), 'https://')) {
                    $bot->setChatMenuButton('Buka SiGAP', $miniApp);
                    $this->info("Tombol menu Mini App: {$miniApp}");
                } else {
                    $this->warn('TELEGRAM_MINIAPP_URL bukan HTTPS; tombol menu tidak diatur.');
                }
            }
        } catch (TelegramApiException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
