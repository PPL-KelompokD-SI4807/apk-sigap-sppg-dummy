<?php

namespace App\Events;

use App\Models\NotifikasiPerjalanan;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Basis event domain perjalanan mobil. Hanya membawa nilai skalar (aman diserialisasi ke antrean);
 * pengiriman pesan Telegram dilakukan listener (bukan di sini). Dipancarkan setelah transaksi commit.
 */
abstract class PerjalananEvent implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $id,
        public readonly int $sppgId,
        public readonly int $jadwalDistribusiId,
        public readonly int $titikLayananId,
        public readonly string $titikLayananNama,
        public readonly ?string $picNama,
        public readonly ?int $picTelegramChatId,
        public readonly bool $dialihkanKeKepala,
        public readonly string $status,
        public readonly string $estimasiTiba,
    ) {}

    public static function dari(NotifikasiPerjalanan $n): static
    {
        $n->loadMissing('titikLayanan');
        $titik = $n->titikLayanan;

        return new static(
            id: (int) $n->id,
            sppgId: (int) $n->sppg_id,
            jadwalDistribusiId: (int) $n->jadwal_distribusi_id,
            titikLayananId: (int) $n->titik_layanan_id,
            titikLayananNama: (string) $titik?->nama,
            picNama: $titik?->pic_nama,
            picTelegramChatId: $titik?->pic_telegram_chat_id,
            dialihkanKeKepala: (bool) $n->dialihkan_ke_kepala,
            status: $n->status_perjalanan->value,
            estimasiTiba: $n->estimasi_tiba->copy()->setTimezone(config('operasional.timezone'))->toIso8601String(),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'sppg_id' => $this->sppgId,
            'jadwal_distribusi_id' => $this->jadwalDistribusiId,
            'titik_layanan' => ['id' => $this->titikLayananId, 'nama' => $this->titikLayananNama],
            'pic' => ['nama' => $this->picNama, 'telegram_chat_id' => $this->picTelegramChatId],
            'dialihkan_ke_kepala' => $this->dialihkanKeKepala,
            'status' => $this->status,
            'estimasi_tiba' => $this->estimasiTiba,
        ];
    }
}
