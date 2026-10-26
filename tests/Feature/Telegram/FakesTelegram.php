<?php

namespace Tests\Feature\Telegram;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Helper tes Bot Telegram: Http::fake untuk Bot API, pembuat update, dan pemeriksa pesan terkirim.
 */
trait FakesTelegram
{
    private int $updateSeq = 1000;

    /** @param array<string, mixed> $stubs stub tambahan (didahulukan) */
    protected function fakeTelegram(array $stubs = []): void
    {
        config(['telegram.miniapp_url' => 'https://sigap.test/miniapp/']);

        // Pabrik HTTP baru agar stub sebelumnya tidak mendahului (stub pertama yang cocok menang).
        $factory = new Factory($this->app->make('events'));
        $factory->preventStrayRequests();
        Http::swap($factory);

        Http::fake($stubs + [
            'api.telegram.org/file/*' => Http::response("\xFF\xD8\xFF\xE0fake-jpeg", 200),
            'api.telegram.org/*/getFile' => Http::response(['ok' => true, 'result' => ['file_id' => 'f1', 'file_size' => 12, 'file_path' => 'photos/f1.jpg']]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);
    }

    /** @return Collection<int, Request> */
    protected function telegramCalls(string $method): Collection
    {
        return collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0])
            ->filter(fn (Request $r) => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/'.$method))
            ->values();
    }

    /** @return Collection<int, Request> */
    protected function messagesTo(int $chatId): Collection
    {
        return $this->telegramCalls('sendMessage')->filter(fn (Request $r) => (string) $r['chat_id'] === (string) $chatId)->values();
    }

    /**
     * @param  array<string, mixed>  $message  field tambahan message (text, photo, voice, ...)
     * @return array<string, mixed>
     */
    protected function privateUpdate(int $fromId, array $message, string $chatType = 'private', ?int $updateId = null): array
    {
        return [
            'update_id' => $updateId ?? ++$this->updateSeq,
            'message' => array_merge([
                'message_id' => $this->updateSeq,
                'date' => time(),
                'chat' => ['id' => $chatType === 'private' ? $fromId : -100123, 'type' => $chatType],
                'from' => ['id' => $fromId, 'is_bot' => false, 'first_name' => 'Siti', 'last_name' => 'Fiktif', 'username' => 'siti_fiktif'],
            ], $message),
        ];
    }

    /** @param array<string, mixed> $update */
    protected function webhook(array $update, ?string $secret = 'test-webhook-secret')
    {
        $headers = $secret === null ? [] : ['X-Telegram-Bot-Api-Secret-Token' => $secret];

        return $this->postJson('/api/v1/telegram/webhook', $update, $headers);
    }

    /** @return array<string, mixed>|null reply_markup terdekode dari permintaan */
    protected function markupOf(Request $request): ?array
    {
        $raw = $request['reply_markup'] ?? null;

        return is_string($raw) ? json_decode($raw, true) : null;
    }
}
