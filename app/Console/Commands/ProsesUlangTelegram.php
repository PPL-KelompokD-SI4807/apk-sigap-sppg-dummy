<?php

namespace App\Console\Commands;

use App\Jobs\ProcessTelegramUpdate;
use App\Models\TelegramUpdate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ProsesUlangTelegram extends Command
{
    /** Update lebih muda dari ini dianggap masih di antrean. */
    public const TUNGGU_MENIT = 5;

    /** Update lebih tua dari ini tidak lagi relevan untuk dibalas. */
    public const BATAS_JAM = 24;

    /** Jeda minimum antar-pengiriman ulang update yang sama. */
    public const JEDA_MENIT = 30;

    protected $signature = 'sigap:proses-ulang-telegram';

    protected $description = 'Kirim ulang ke antrean update webhook Telegram yang tersimpan tetapi belum diproses (mis. dispatch gagal saat webhook diterima)';

    public function handle(): int
    {
        $jumlah = 0;

        TelegramUpdate::query()
            ->whereNull('processed_at')
            ->where('created_at', '<', now()->subMinutes(self::TUNGGU_MENIT))
            ->where('created_at', '>', now()->subHours(self::BATAS_JAM))
            ->orderBy('id')
            ->chunkById(200, function ($updates) use (&$jumlah): void {
                foreach ($updates as $update) {
                    if (Cache::add('telegram-update:ulang:'.$update->id, true, now()->addMinutes(self::JEDA_MENIT))) {
                        ProcessTelegramUpdate::dispatch($update->id);
                        $jumlah++;
                    }
                }
            });

        $this->info("Update Telegram dikirim ulang: {$jumlah}");

        return self::SUCCESS;
    }
}
