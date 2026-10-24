<?php

namespace App\Http\Resources\BahanBaku;

use App\Models\BahanBaku;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BahanBaku */
class BahanBakuResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var BahanBaku $b */
        $b = $this->resource;

        return [
            'id' => $b->id,
            'sppg_id' => $b->sppg_id,
            'pemasok_id' => $b->pemasok_id,
            'pemasok' => $this->whenLoaded('pemasok', fn () => $b->pemasok === null ? null : [
                'id' => $b->pemasok->id,
                'nama' => $b->pemasok->nama,
            ]),
            'kode' => $b->kode,
            'nama' => $b->nama,
            'kategori' => $b->kategori?->value,
            'kategori_label' => $b->kategori?->label(),
            'satuan' => $b->satuan,
            'stok' => (float) $b->stok,
            'stok_minimum' => (float) $b->stok_minimum,
            'kedaluwarsa_terdekat' => $b->kedaluwarsa_terdekat?->toDateString(),
            'stok_menipis' => $b->isStokMenipis(),
            'akan_kedaluwarsa' => $b->isAkanKedaluwarsa((int) config('gudang.kedaluwarsa_hari', 3)),
            'created_at' => $b->created_at?->toIso8601String(),
            'updated_at' => $b->updated_at?->toIso8601String(),
        ];
    }
}
