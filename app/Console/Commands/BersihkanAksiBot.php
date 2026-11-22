<?php

namespace App\Console\Commands;

use App\Services\Telegram\Bot\BotAksiService;
use Illuminate\Console\Command;

class BersihkanAksiBot extends Command
{
    protected $signature = 'sigap:bersihkan-aksi-bot';

    protected $description = 'Hapus baris bot_aksi (token tombol Bot Telegram) yang lebih tua dari config telegram.aksi_retensi_hari';

    public function handle(BotAksiService $aksi): int
    {
        $jumlah = $aksi->bersihkan((int) config('telegram.aksi_retensi_hari', 30));
        $this->info("Baris aksi bot dihapus: {$jumlah}");

        return self::SUCCESS;
    }
}
