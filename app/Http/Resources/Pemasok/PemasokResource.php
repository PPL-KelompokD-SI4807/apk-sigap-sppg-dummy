<?php

namespace App\Http\Resources\Pemasok;

use App\Models\Pemasok;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Pemasok */
class PemasokResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Pemasok $p */
        $p = $this->resource;

        return [
            'id' => $p->id,
            'sppg_id' => $p->sppg_id,
            'nama' => $p->nama,
            'jenis' => $p->jenis->value,
            'kontak' => $p->kontak,
            'alamat' => $p->alamat,
            'jenis_bahan' => $p->jenis_bahan,
            'is_active' => $p->is_active,
            'bahan_baku_count' => $this->whenCounted('bahanBakus'),
            'created_at' => $p->created_at?->toIso8601String(),
            'updated_at' => $p->updated_at?->toIso8601String(),
        ];
    }
}
