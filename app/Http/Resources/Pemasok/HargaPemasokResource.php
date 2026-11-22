<?php

namespace App\Http\Resources\Pemasok;

use App\Models\HargaPemasok;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin HargaPemasok */
class HargaPemasokResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var HargaPemasok $h */
        $h = $this->resource;
        $hariIni = now((string) config('operasional.timezone', 'Asia/Jakarta'))->toDateString();

        return [
            'id' => $h->id,
            'sppg_id' => $h->sppg_id,
            'pemasok_id' => $h->pemasok_id,
            'bahan_baku_id' => $h->bahan_baku_id,
            'bahan_baku_nama' => $h->relationLoaded('bahanBaku') ? $h->bahanBaku?->nama : null,
            'satuan' => $h->relationLoaded('bahanBaku') ? $h->bahanBaku?->satuan : null,
            'harga' => (float) $h->harga,
            'gram_per_satuan' => $h->gram_per_satuan === null ? null : (float) $h->gram_per_satuan,
            'berlaku_mulai' => $h->berlaku_mulai->toDateString(),
            'sudah_berlaku' => $h->berlaku_mulai->toDateString() <= $hariIni,
            'catatan' => $h->catatan,
            'dicatat_oleh' => $h->dicatat_oleh,
            'created_at' => $h->created_at?->toIso8601String(),
        ];
    }
}
