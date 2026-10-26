<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Bahan baku (stok > 0) kedaluwarsa dalam N hari atau sudah lewat (sekali per bahan+tanggal per hari). */
class BahanAkanKedaluwarsa
{
    use Dispatchable;

    public function __construct(
        public readonly int $sppgId,
        public readonly int $bahanBakuId,
        public readonly string $nama,
        public readonly string $satuan,
        public readonly string $stok,
        public readonly string $tanggalKedaluwarsa,
        public readonly int $sisaHari,
    ) {}
}
