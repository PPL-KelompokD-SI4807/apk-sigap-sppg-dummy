<?php

namespace App\Http\Resources\MenuGizi;

use App\Models\StandarGizi;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StandarGizi */
class StandarGiziResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var StandarGizi $s */
        $s = $this->resource;
        $data = [
            'id' => $s->id,
            'sppg_id' => $s->sppg_id,
            'kelompok_sasaran' => $s->kelompok_sasaran?->value,
        ];

        foreach (['energi', 'protein', 'lemak', 'karbohidrat', 'serat'] as $zat) {
            $data["{$zat}_min"] = (float) $s->{"{$zat}_min"};
            $data["{$zat}_max"] = (float) $s->{"{$zat}_max"};
        }

        $data['updated_at'] = $s->updated_at?->toIso8601String();

        return $data;
    }
}
