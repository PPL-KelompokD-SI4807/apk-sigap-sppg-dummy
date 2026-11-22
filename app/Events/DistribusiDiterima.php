<?php

namespace App\Events;

use App\Models\JadwalDistribusi;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Distribusi tercatat diterima di titik layanan (status jadwal "diterima"). Listener mengirim pesan konfirmasi singkat
 * ke PIC titik layanan (atau Kepala SPPG bila PIC belum terhubung Telegram). Hanya nilai skalar; tanpa data pribadi
 * selain chat id PIC yang sudah tersimpan di titik layanan.
 */
class DistribusiDiterima implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $sppgId,
        public readonly int $jadwalDistribusiId,
        public readonly string $titikLayananNama,
        public readonly ?int $picTelegramChatId,
        public readonly bool $dialihkanKeKepala,
        public readonly string $waktuTerima,
    ) {}

    public static function dari(JadwalDistribusi $jadwal, CarbonInterface $waktuTerima): self
    {
        $jadwal->loadMissing('titikLayanan');
        $titik = $jadwal->titikLayanan;

        return new self(
            sppgId: (int) $jadwal->sppg_id,
            jadwalDistribusiId: (int) $jadwal->id,
            titikLayananNama: (string) $titik?->nama,
            picTelegramChatId: $titik?->pic_telegram_chat_id,
            dialihkanKeKepala: $titik === null || ! $titik->picTerhubung(),
            waktuTerima: $waktuTerima->copy()->setTimezone(config('operasional.timezone'))->toIso8601String(),
        );
    }
}
