<?php

namespace App\Services\Ai;

use App\Services\Ai\Llm\LlmException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Pelindung token GLOBAL per model (D8) agar batas penyedia (mis. Groq gratis 8K token/menit,
 * 200K token/hari per model) tidak memicu 429 beruntun. Melengkapi rate limit per pengguna.
 *
 * - check(): sebelum memanggil penyedia; bila pemakaian + estimasi permintaan melewati ambang,
 *   melempar LlmException kategori sibuk (per menit) / kuota (per hari) TANPA memanggil penyedia.
 * - record(): setelah respons; menambah penghitung dari usage penyedia (angka saja) atau estimasi.
 * Penghitung disimpan di Cache (jendela menit & hari UTC). Ambang 0 = nonaktif.
 * Estimasi token ~ karakter/4 (kasar; tanpa tokenizer penyedia).
 */
class TokenBudget
{
    /** Estimasi kasar biaya token satu gambar untuk model vision. */
    public const IMAGE_TOKENS = 1500;

    public static function estimate(mixed ...$parts): int
    {
        $chars = 0;

        foreach ($parts as $part) {
            $chars += is_string($part)
                ? mb_strlen($part)
                : mb_strlen((string) json_encode($part, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
        }

        return (int) ceil($chars / 4);
    }

    /**
     * Estimasi satu permintaan chat: prompt sistem + pesan (tanpa data base64 gambar) + definisi tool.
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $schemas
     */
    public static function estimateRequest(string $system, array $messages, array $schemas): int
    {
        $images = 0;
        $plain = array_map(function (array $m) use (&$images) {
            $images += count((array) ($m['images'] ?? []));
            unset($m['images']);

            return $m;
        }, $messages);

        return self::estimate($system, $plain, $schemas) + $images * self::IMAGE_TOKENS;
    }

    /** @throws LlmException */
    public function check(string $model, int $estimate): void
    {
        $perDay = max(0, (int) config('ai.token_budget.per_day', 0));
        $perMinute = max(0, (int) config('ai.token_budget.per_minute', 0));

        if ($perDay > 0 && $this->used('d', $model) + $estimate > $perDay) {
            throw LlmException::quota();
        }

        if ($perMinute > 0 && $this->used('m', $model) + $estimate > $perMinute) {
            throw LlmException::busy();
        }
    }

    public function record(string $model, int $tokens): void
    {
        if ($tokens <= 0) {
            return;
        }

        foreach (['m' => 120, 'd' => 2 * 86400] as $window => $ttl) {
            $key = $this->key($window, $model);
            Cache::add($key, 0, $ttl);
            Cache::increment($key, $tokens);
        }
    }

    public function used(string $window, string $model): int
    {
        return (int) Cache::get($this->key($window, $model), 0);
    }

    private function key(string $window, string $model): string
    {
        $now = Carbon::now('UTC');

        return 'ai:token:'.$window.':'.substr(sha1($model), 0, 12).':'.($window === 'm' ? $now->format('YmdHi') : $now->format('Ymd'));
    }
}
