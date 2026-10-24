<?php

namespace App\Http\Resources\LogProduksi;

use App\Http\Resources\LampiranResource;
use App\Models\LogProduksi;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LogProduksi */
class LogProduksiResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var LogProduksi $l */
        $l = $this->resource;
        $jadwal = $l->relationLoaded('jadwalProduksi') ? $l->jadwalProduksi : null;
        $target = $jadwal?->target_porsi;
        $selisih = $target === null ? null : $l->realisasi_porsi - $target;

        return [
            'id' => $l->id,
            'sppg_id' => $l->sppg_id,
            'jadwal_produksi_id' => $l->jadwal_produksi_id,
            'jadwal' => $this->whenLoaded('jadwalProduksi', fn () => $jadwal === null ? null : [
                'id' => $jadwal->id,
                'tanggal_produksi' => $jadwal->tanggal_produksi?->toDateString(),
                'target_porsi' => $jadwal->target_porsi,
                'status' => $jadwal->status?->value,
                'menu' => $jadwal->relationLoaded('menu') && $jadwal->menu !== null
                    ? ['id' => $jadwal->menu->id, 'nama_menu' => $jadwal->menu->nama_menu]
                    : null,
            ]),
            'kode_batch' => $l->kode_batch,
            'realisasi_porsi' => $l->realisasi_porsi,
            'target_porsi' => $target,
            'selisih_porsi' => $selisih,
            'persentase_capaian' => $target ? round($l->realisasi_porsi / $target * 100, 1) : null,
            'peringatan' => match (true) {
                $selisih === null || $selisih === 0 => null,
                $selisih < 0 => sprintf('Realisasi %d porsi kurang %d dari target %d.', $l->realisasi_porsi, abs($selisih), $target),
                default => sprintf('Realisasi %d porsi melebihi target %d sebanyak %d.', $l->realisasi_porsi, $target, $selisih),
            },
            'sisa_makanan_kg' => (float) $l->sisa_makanan_kg,
            'waktu_mulai' => $l->waktu_mulai === null ? null : substr((string) $l->waktu_mulai, 0, 5),
            'jam_selesai' => $l->jam_selesai === null ? null : substr((string) $l->jam_selesai, 0, 5),
            'kendala' => $l->kendala,
            'lampiran' => LampiranResource::collection($this->whenLoaded('lampirans', fn () => $l->lampirans, [])),
            'dicatat_oleh' => $l->dicatat_oleh,
            'pencatat' => $this->whenLoaded('pencatat', fn () => $l->pencatat?->name),
            'created_at' => $l->created_at?->toIso8601String(),
            'updated_at' => $l->updated_at?->toIso8601String(),
        ];
    }
}
