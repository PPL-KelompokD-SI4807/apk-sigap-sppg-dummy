<?php

namespace App\Console\Commands;

use App\Services\Auth\BrowserTepercayaService;
use Illuminate\Console\Command;

class BersihkanBrowserTepercaya extends Command
{
    protected $signature = 'sigap:bersihkan-browser-tepercaya';

    protected $description = 'Tandai dicabut browser web tepercaya yang lewat 30 hari (R04-3) dan hapus sesi web turunannya';

    public function handle(BrowserTepercayaService $browser): int
    {
        $jumlah = $browser->bersihkanKedaluwarsa();
        $this->info("Browser tepercaya kedaluwarsa dicabut: {$jumlah}");

        return self::SUCCESS;
    }
}
