<?php

namespace App\Events;

use App\Models\FeedbackKeluhan;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

/**
 * Keluhan berprioritas kritis dieskalasi (notifikasi segera ke Kepala SPPG dan Ahli Gizi oleh listener).
 */
class KeluhanKritis implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $id,
        public readonly int $sppgId,
        public readonly string $kategori,
        public readonly string $prioritas,
        public readonly ?int $titikLayananId,
        public readonly ?string $titikLayananNama,
        public readonly ?int $jadwalProduksiId,
        public readonly string $deskripsi,
        public readonly string $dieskalasiPada,
    ) {}

    public static function dari(FeedbackKeluhan $k): self
    {
        $k->loadMissing('titikLayanan');

        return new self(
            id: (int) $k->id,
            sppgId: (int) $k->sppg_id,
            kategori: $k->kategori->value,
            prioritas: $k->prioritas->value,
            titikLayananId: $k->titik_layanan_id,
            titikLayananNama: $k->titikLayanan?->nama,
            jadwalProduksiId: $k->jadwal_produksi_id,
            deskripsi: Str::limit($k->deskripsi, 200),
            dieskalasiPada: $k->dieskalasi_pada->copy()->setTimezone(config('operasional.timezone'))->toIso8601String(),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'sppg_id' => $this->sppgId,
            'kategori' => $this->kategori,
            'prioritas' => $this->prioritas,
            'titik_layanan' => $this->titikLayananId === null ? null : ['id' => $this->titikLayananId, 'nama' => $this->titikLayananNama],
            'jadwal_produksi_id' => $this->jadwalProduksiId,
            'deskripsi' => $this->deskripsi,
            'dieskalasi_pada' => $this->dieskalasiPada,
        ];
    }
}
