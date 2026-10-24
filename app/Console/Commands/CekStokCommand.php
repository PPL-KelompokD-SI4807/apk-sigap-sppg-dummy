<?php

namespace App\Console\Commands;

use App\Services\Stock\StockService;
use Illuminate\Console\Command;

class CekStokCommand extends Command
{
    protected $signature = 'sigap:cek-stok {--hari= : Ambang hari kedaluwarsa (default config gudang.kedaluwarsa_hari)}';

    protected $description = 'Periksa stok minimum dan bahan akan kedaluwarsa; pancarkan event StokMenipis/BahanAkanKedaluwarsa (sekali per kondisi per hari).';

    public function handle(StockService $stok): int
    {
        $hari = $this->option('hari');
        $hasil = $stok->peringatanHarian(is_numeric($hari) ? (int) $hari : null);

        $this->info("Stok menipis: {$hasil['stok_menipis']} peringatan; kedaluwarsa: {$hasil['kedaluwarsa']} peringatan.");

        return self::SUCCESS;
    }
}
