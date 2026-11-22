<?php

namespace App\Console\Commands;

use App\Services\Auth\SesiService;
use Illuminate\Console\Command;

class BersihkanPerangkatKedaluwarsa extends Command
{
    protected $signature = 'sigap:bersihkan-perangkat';

    protected $description = 'Tandai dicabut kepercayaan perangkat yang diverifikasi token bot lebih lama dari batas absolut sesi (180 hari)';

    public function handle(SesiService $sesi): int
    {
        $jumlah = $sesi->bersihkanPerangkatKedaluwarsa();
        $this->info("Kepercayaan perangkat kedaluwarsa dicabut: {$jumlah}");

        return self::SUCCESS;
    }
}
