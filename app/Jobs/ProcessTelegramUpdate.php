<?php

namespace App\Jobs;

use App\Models\TelegramUpdate;
use App\Services\Telegram\TelegramUpdateHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Memproses satu pembaruan webhook Telegram di antrean (NFR-03: webhook membalas 200 cepat).
 * Tidak dicoba ulang otomatis: konsumsi undangan dan balasan bot tidak boleh terjadi dua kali.
 */
class ProcessTelegramUpdate implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly int $telegramUpdateId) {}

    public function handle(TelegramUpdateHandler $handler): void
    {
        $update = TelegramUpdate::query()->find($this->telegramUpdateId);

        if ($update === null || $update->processed_at !== null) {
            return;
        }

        $handler->handle($update);
    }

    public function failed(\Throwable $e): void
    {
        $update = TelegramUpdate::query()->find($this->telegramUpdateId);

        if ($update !== null) {
            TelegramUpdateHandler::finish($update, 'gagal');
        }
    }
}
