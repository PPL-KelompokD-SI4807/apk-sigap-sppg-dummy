<?php

namespace App\Jobs;

use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Telegram\AiChatHandler;
use App\Services\Telegram\TelegramUpdateHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pesan chat pengguna terdaftar -> Agen AI (FR-11). Tidak dicoba ulang otomatis agar tidak
 * menggandakan draf/biaya LLM; kegagalan dibalas fallback formulir manual oleh AiAgentService.
 */
class ProcessAiMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(
        public readonly int $telegramUpdateId,
        public readonly int $userId,
    ) {}

    public function handle(AiChatHandler $handler): void
    {
        $update = TelegramUpdate::query()->find($this->telegramUpdateId);
        $user = User::query()->withoutGlobalScopes()->find($this->userId);

        if ($update === null || ($update->payload['ringkas'] ?? false) === true) {
            return;
        }

        if ($user === null || ! $user->canAuthenticate()) {
            TelegramUpdateHandler::finish($update, 'ai_ditolak');

            return;
        }

        $handler->handle($update, $user);
    }

    public function failed(\Throwable $e): void
    {
        $update = TelegramUpdate::query()->find($this->telegramUpdateId);

        if ($update !== null) {
            TelegramUpdateHandler::finish($update, 'ai_gagal');
        }
    }
}
