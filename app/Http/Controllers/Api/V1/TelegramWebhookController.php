<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessTelegramUpdate;
use App\Models\TelegramUpdate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * POST /api/v1/telegram/webhook (publik, tanpa Sanctum).
 *
 * - Header X-Telegram-Bot-Api-Secret-Token dibandingkan constant-time (hash_equals) dengan
 *   config('telegram.webhook_secret'); secret kosong = semua permintaan ditolak (403).
 * - Idempoten: update_id unik di telegram_updates (INSERT OR IGNORE); duplikat dibalas 200.
 * - Selalu membalas cepat; pemrosesan di job ProcessTelegramUpdate. Galat internal tidak bocor.
 */
class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = config('telegram.webhook_secret');
        $given = $request->header('X-Telegram-Bot-Api-Secret-Token');

        if (! is_string($secret) || $secret === '' || ! is_string($given) || ! hash_equals($secret, $given)) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $raw = $request->getContent();

        if (strlen($raw) > (int) config('telegram.webhook_max_body', 262144)) {
            return response()->json(['message' => 'Payload terlalu besar.'], 413);
        }

        $payload = json_decode($raw, true, 32);
        $updateId = is_array($payload) ? ($payload['update_id'] ?? null) : null;

        if (! is_int($updateId) || $updateId <= 0) {
            // Bukan update sah: diabaikan (200 agar Telegram tidak mengulang).
            return response()->json(['ok' => true]);
        }

        try {
            $inserted = TelegramUpdate::query()->insertOrIgnore([
                'update_id' => $updateId,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 1) {
                $id = (int) TelegramUpdate::query()->where('update_id', $updateId)->value('id');
                ProcessTelegramUpdate::dispatch($id);
            }
        } catch (Throwable $e) {
            report($e);
            Log::warning('telegram.webhook_gagal', ['update_id' => $updateId, 'galat' => class_basename($e)]);
        }

        return response()->json(['ok' => true]);
    }
}
