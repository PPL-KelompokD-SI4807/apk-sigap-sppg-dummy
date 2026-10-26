<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Stok tidak cukup untuk kebutuhan bahan sebuah jadwal produksi (notifikasi ke Staf Gudang).
 *
 * `kekurangan`: list<array{bahan_baku_id: int, nama: string, satuan: string, kebutuhan: float, stok: float, kekurangan: float}>
 */
class KekuranganBahan
{
    use Dispatchable;

    /** @param  list<array{bahan_baku_id: int, nama: string, satuan: string, kebutuhan: float, stok: float, kekurangan: float}>  $kekurangan */
    public function __construct(
        public readonly int $sppgId,
        public readonly int $jadwalProduksiId,
        public readonly string $tanggalProduksi,
        public readonly string $namaMenu,
        public readonly int $targetPorsi,
        public readonly array $kekurangan,
    ) {}
}
