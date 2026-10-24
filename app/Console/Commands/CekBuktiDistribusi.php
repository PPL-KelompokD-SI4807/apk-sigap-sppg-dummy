<?php

namespace App\Console\Commands;

use App\Services\Distribusi\BuktiDistribusiService;
use Illuminate\Console\Command;

class CekBuktiDistribusi extends Command
{
    protected $signature = 'sigap:cek-bukti-distribusi';

    protected $description = 'Cari jadwal distribusi yang sudah berangkat tetapi bukti belum masuk melewati jam rencana tiba + batas, lalu pancarkan BuktiDistribusiBelumMasuk (sekali per jadwal)';

    public function handle(BuktiDistribusiService $service): int
    {
        $jumlah = $service->deteksiBuktiBelumMasuk();
        $this->info("Jadwal dengan bukti belum masuk (baru): {$jumlah}");

        return self::SUCCESS;
    }
}
