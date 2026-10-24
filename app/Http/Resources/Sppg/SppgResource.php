<?php

namespace App\Http\Resources\Sppg;

use App\Models\Sppg;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Sppg */
class SppgResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Sppg $sppg */
        $sppg = $this->resource;

        return [
            'id' => $sppg->id,
            'kode' => $sppg->kode,
            'nama' => $sppg->nama,
            'alamat' => $sppg->alamat,
            'mitra_yayasan' => $sppg->mitra_yayasan,
            'telepon' => $sppg->telepon,
            'kapasitas_porsi_harian' => $sppg->kapasitas_porsi_harian,
            'status_operasional' => $sppg->status_operasional->value,
            'created_at' => $sppg->created_at?->toIso8601String(),
            'updated_at' => $sppg->updated_at?->toIso8601String(),
        ];
    }
}
