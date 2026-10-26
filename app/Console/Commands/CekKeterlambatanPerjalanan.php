<?php

namespace App\Console\Commands;

use App\Services\Perjalanan\TripNotificationService;
use Illuminate\Console\Command;

class CekKeterlambatanPerjalanan extends Command
{
    protected $signature = 'sigap:cek-keterlambatan';

    protected $description = 'Tandai perjalanan mobil yang melewati estimasi tiba dan pancarkan PerjalananTerlambat (sekali per perjalanan)';

    public function handle(TripNotificationService $service): int
    {
        $jumlah = $service->deteksiKeterlambatan();
        $this->info("Perjalanan terlambat baru: {$jumlah}");

        return self::SUCCESS;
    }
}
