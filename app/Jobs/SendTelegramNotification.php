<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Notification\DeliveryTracker;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Kirim satu notifikasi bot (HTML ter-escape) ke satu chat (NFR-03, NFR-07).
 *
 * - Retry: maks 3 galat sementara dengan jeda bertahap 10/30/90 detik, batas waktu 30 menit;
 *   429 mematuhi retry_after (release, tidak dihitung galat); 400/403 (chat tidak ada / bot
 *   diblokir) tidak dicoba ulang.
 * - Penerima pengguna dicek ulang saat kirim: nonaktif/terhapus/telegram berubah -> dilewati.
 * - Log aman: jenis, status, dan hash chat id (bukan isi pesan, bukan token).
 */
class SendTelegramNotification implements ShouldQueue
{
    use Queueable;

    public int $maxExceptions = 3;

    public int $timeout = 30;

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     * @param  array{type: string, id: int}|null  $track
     */
    public function __construct(
        public readonly int $chatId,
        public readonly string $html,
        public readonly ?array $replyMarkup,
        public readonly ?int $userId,
        public readonly string $type,
        public readonly ?array $track = null,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new RateLimited('telegram-out')];
    }

    public function handle(TelegramBotService $bot, DeliveryTracker $tracker): void
    {
        if ($this->userId !== null && ! $this->recipientStillValid()) {
            $this->log('dilewati');

            return;
        }

        try {
            $bot->sendHtml($this->chatId, $this->html, $this->replyMarkup);
        } catch (TelegramApiException $e) {
            if ($e->isRateLimited()) {
                $this->release(max(1, min(60, (int) ($e->retryAfter ?? 5))));

                return;
            }

            if ($e->isPermanent()) {
                $tracker->failed($this->track, 'Telegram menolak pengiriman ('.$e->errorCode.').');
                $this->log('ditolak', $e->errorCode);

                return;
            }

            throw $e;
        }

        $tracker->sent($this->track);
        $this->log('terkirim');
    }

    public function failed(?Throwable $e): void
    {
        app(DeliveryTracker::class)->failed($this->track, 'Gagal setelah percobaan ulang.');
        $this->log('gagal', $e instanceof TelegramApiException ? $e->errorCode : null);
    }

    private function recipientStillValid(): bool
    {
        $user = User::query()->withoutGlobalScopes()->find($this->userId);

        return $user !== null && $user->canAuthenticate() && (int) $user->telegram_id === $this->chatId;
    }

    private function log(string $status, ?int $code = null): void
    {
        Log::info('notifikasi.kirim', array_filter([
            'jenis' => $this->type,
            'status' => $status,
            'kode' => $code,
            'penerima' => substr(hash('sha256', (string) $this->chatId), 0, 12),
            'user_id' => $this->userId,
        ], fn ($v) => $v !== null));
    }
}
