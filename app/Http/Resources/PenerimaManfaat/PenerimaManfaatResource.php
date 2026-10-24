<?php

namespace App\Http\Resources\PenerimaManfaat;

use App\Models\PenerimaManfaat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PenerimaManfaat */
class PenerimaManfaatResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var PenerimaManfaat $pm */
        $pm = $this->resource;

        return [
            'id' => $pm->id,
            'sppg_id' => $pm->sppg_id,
            'titik_layanan_id' => $pm->titik_layanan_id,
            'titik_layanan_nama' => $this->whenLoaded('titikLayanan', fn () => $pm->titikLayanan?->nama),
            'nama' => $pm->nama,
            'kelompok_sasaran' => $pm->kelompok_sasaran->value,
            'kelas_keterangan' => $pm->kelas_keterangan,
            'catatan_alergi' => $pm->catatan_alergi,
            'is_active' => $pm->is_active,
            'created_at' => $pm->created_at?->toIso8601String(),
            'updated_at' => $pm->updated_at?->toIso8601String(),
        ];
    }
}
