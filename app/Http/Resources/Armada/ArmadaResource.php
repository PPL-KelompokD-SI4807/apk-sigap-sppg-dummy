<?php

namespace App\Http\Resources\Armada;

use App\Models\Armada;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Armada */
class ArmadaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Armada $armada */
        $armada = $this->resource;

        return [
            'id' => $armada->id,
            'sppg_id' => $armada->sppg_id,
            'plat_nomor' => $armada->plat_nomor,
            'jenis' => $armada->jenis,
            'kapasitas_box' => $armada->kapasitas_box,
            'status' => $armada->status->value,
            'created_at' => $armada->created_at?->toIso8601String(),
            'updated_at' => $armada->updated_at?->toIso8601String(),
        ];
    }
}
