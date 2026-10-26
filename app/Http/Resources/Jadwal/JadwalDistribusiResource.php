<?php

namespace App\Http\Resources\Jadwal;

use App\Enums\HasilMutu;
use App\Models\JadwalDistribusi;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin JadwalDistribusi */
class JadwalDistribusiResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var JadwalDistribusi $d */
        $d = $this->resource;

        return [
            'id' => $d->id,
            'sppg_id' => $d->sppg_id,
            'jadwal_produksi_id' => $d->jadwal_produksi_id,
            'titik_layanan_id' => $d->titik_layanan_id,
            'titik_layanan' => $this->whenLoaded('titikLayanan', fn () => $d->titikLayanan === null ? null : [
                'id' => $d->titikLayanan->id,
                'nama' => $d->titikLayanan->nama,
            ]),
            'armada_id' => $d->armada_id,
            'armada' => $this->whenLoaded('armada', fn () => $d->armada === null ? null : [
                'id' => $d->armada->id,
                'plat_nomor' => $d->armada->plat_nomor,
            ]),
            'pengemudi_id' => $d->pengemudi_id,
            'pengemudi' => $this->whenLoaded('pengemudi', fn () => $d->pengemudi === null ? null : [
                'id' => $d->pengemudi->id,
                'nama' => $d->pengemudi->nama,
            ]),
            'petugas_id' => $d->petugas_id,
            'petugas' => $this->whenLoaded('petugas', fn () => $d->petugas?->name),
            'dibuat_oleh' => $d->getAttribute('dibuat_oleh'),
            'tanggal_distribusi' => $d->tanggal_distribusi?->toDateString(),
            'jam_berangkat' => $d->jam_berangkat === null ? null : substr((string) $d->jam_berangkat, 0, 5),
            'jam_rencana_tiba' => $d->jam_rencana_tiba === null ? null : substr((string) $d->jam_rencana_tiba, 0, 5),
            'jumlah_porsi' => $d->jumlah_porsi,
            'status' => $d->status?->value,
            'status_label' => $d->status?->label(),
            'layak_distribusi' => $this->when($d->relationLoaded('jadwalProduksi'), function () use ($d): bool {
                $produksi = $d->jadwalProduksi;

                if ($produksi === null) {
                    return false;
                }

                $mutu = $produksi->relationLoaded('pemeriksaanTerbaru') ? $produksi->pemeriksaanTerbaru : $produksi->pemeriksaanMutuTerbaru();

                return $mutu?->hasil === HasilMutu::Layak;
            }),
            'catatan' => $d->catatan,
            'created_at' => $d->created_at?->toIso8601String(),
            'updated_at' => $d->updated_at?->toIso8601String(),
        ];
    }
}
