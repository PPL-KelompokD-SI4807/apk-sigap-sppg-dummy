<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Stok bahan baku <= stok minimum (dipicu sigap:cek-stok, sekali per bahan per hari). */
class StokMenipis
{
    use Dispatchable;

    public function __construct(
        public readonly int $sppgId,
        public readonly int $bahanBakuId,
        public readonly string $nama,
        public readonly string $satuan,
        public readonly string $stok,
        public readonly string $stokMinimum,
    ) {}
}
