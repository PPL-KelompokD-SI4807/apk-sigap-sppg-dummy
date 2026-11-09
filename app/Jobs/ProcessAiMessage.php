<?php

namespace App\Jobs;

use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Auth\SesiService;
use App\Services\Telegram\AiChatHandler;
use App\Services\Telegram\TelegramUpdateHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Log;

/**
 * Pesan chat pengguna terdaftar dengan sesi login penuh (R-03) -> Agen AI (FR-11).
 *
 * Exception TIDAK dicoba ulang (maxExceptions = 1) dan timeout langsung gagal (failOnTimeout) agar tidak menggandakan
 * draf/biaya LLM; kegagalan dibalas fallback formulir manual oleh AiAgentService. Satu-satunya proses ulang: pesan FOTO
 * dibalas 429 token per menit sebelum ada draf -> AiChatHandler menyimpan keadaan tunda dan mengembalikan jeda -> job
 * di-release (bukan diulang di dalam eksekusi yang sama), maksimal config ai.retry_429.maks kali (0..2).
 * Percobaan ke-2 dst. TANPA keadaan tunda (job diambil ulang setelah worker mati / retry_after antrean) diselesaikan
 * tanpa memproses ulang: tidak ada interaksi, vision, draf, atau balasan baru. Driver sync tidak pernah menunda.
 * Setiap jalur keluar (termasuk failed()) membersihkan keadaan tunda + penanda foto milik update ini.
 * retry_after antrean (config/queue.php, bawaan 150) harus > $timeout.
 */
class ProcessAiMessage implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $maxExceptions = 1;

    public int $timeout = 90;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $telegramUpdateId,
        public readonly int $userId,
    ) {
        $this->tries = 1 + self::maksUlang();
    }

    public static function maksUlang(): int
    {
        return max(0, min(2, (int) config('ai.retry_429.maks', 2)));
    }

    public function handle(AiChatHandler $handler, SesiService $sesi): void
    {
        $update = TelegramUpdate::query()->find($this->telegramUpdateId);
        $user = User::query()->withoutGlobalScopes()->find($this->userId);

        if ($update === null || ($update->payload['ringkas'] ?? false) === true) {
            AiChatHandler::bersihkan($this->telegramUpdateId);

            return;
        }

        if ($user === null || ! $user->canAuthenticate()) {
            $this->selesai($update, 'ai_ditolak');

            return;
        }

        // Sesi bisa dicabut setelah pesan masuk antrean (juga di antara percobaan).
        if (! $sesi->punyaSesiPenuh($user)) {
            $this->selesai($update, 'ai_tanpa_sesi');

            return;
        }

        $ke = max(1, $this->attempts());

        // Diambil ulang bukan karena release tunda (worker mati/timeout/retry_after): jangan proses ulang.
        if ($ke > 1 && ! AiChatHandler::adaKeadaan($this->telegramUpdateId)) {
            Log::warning('ai.job_diulang_tanpa_tunda', ['update_id' => $this->telegramUpdateId, 'percobaan' => $ke]);
            $this->selesai($update, 'ai_diulang_dilewati');

            return;
        }

        $bolehTunda = $this->job !== null && ! $this->job instanceof SyncJob && $ke <= self::maksUlang();
        $jeda = $handler->handle($update, $user, $ke, $bolehTunda);

        if ($jeda !== null) {
            $this->release($jeda);
        }
    }

    public function failed(\Throwable $e): void
    {
        $update = TelegramUpdate::query()->find($this->telegramUpdateId);

        if ($update !== null) {
            TelegramUpdateHandler::finish($update, 'ai_gagal');
        }

        // Hasil baca foto proses ulang + penanda foto tidak dibiarkan sampai TTL habis.
        AiChatHandler::bersihkan($this->telegramUpdateId);
    }

    private function selesai(TelegramUpdate $update, string $jenis): void
    {
        TelegramUpdateHandler::finish($update, $jenis);
        AiChatHandler::bersihkan($this->telegramUpdateId);
    }
}
