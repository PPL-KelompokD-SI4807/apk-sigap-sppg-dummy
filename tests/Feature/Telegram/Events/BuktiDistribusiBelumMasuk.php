<?php

namespace Tests\Feature\Telegram\Events;

/**
 * Event buatan untuk tes: bentuk payload yang DIHARAPKAN dari App\Events\BuktiDistribusiBelumMasuk
 * (belum dibuat). Lihat docs/api/notifikasi.md.
 */
class BuktiDistribusiBelumMasuk
{
    public function __construct(
        public readonly int $sppgId,
        public readonly int $jadwalDistribusiId,
        public readonly int $titikLayananId,
        public readonly string $titikLayananNama,
        public readonly string $jamRencanaTiba,
        public readonly int $menitSejakRencana,
    ) {}
}
