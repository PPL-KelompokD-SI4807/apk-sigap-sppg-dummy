<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Pemeriksaan mutu berhasil "Tidak Layak" (dibuat atau dikoreksi menjadi tidak layak).
 * Distribusi terkait ditahan; notifikasi ke Kepala SPPG dan Ahli Gizi.
 */
class MutuTidakLayak
{
    use Dispatchable;

    public function __construct(
        public readonly int $sppgId,
        public readonly int $pemeriksaanMutuId,
        public readonly int $jadwalProduksiId,
        public readonly string $tanggalProduksi,
        public readonly string $namaMenu,
        public readonly ?int $pemeriksaId,
        public readonly ?string $tindakanKorektif,
        public readonly int $jumlahDistribusiDitahan,
    ) {}
}
