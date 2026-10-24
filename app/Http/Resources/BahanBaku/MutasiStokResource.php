<?php

namespace App\Http\Resources\BahanBaku;

use App\Models\MutasiStok;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MutasiStok */
class MutasiStokResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var MutasiStok $m */
        $m = $this->resource;

        return [
            'id' => $m->id,
            'bahan_baku_id' => $m->bahan_baku_id,
            'jenis' => $m->jenis?->value,
            'jumlah' => (float) $m->jumlah,
            'stok_setelah' => $m->getAttribute('stok_setelah') === null ? null : (float) $m->getAttribute('stok_setelah'),
            'harga_satuan' => $m->harga_satuan === null ? null : (float) $m->harga_satuan,
            'tanggal_mutasi' => $m->tanggal_mutasi?->toDateString(),
            'tanggal_kedaluwarsa' => $m->tanggal_kedaluwarsa?->toDateString(),
            'jadwal_produksi_id' => $m->jadwal_produksi_id,
            'pemasok_id' => $m->pemasok_id,
            'pemasok' => $this->whenLoaded('pemasok', fn () => $m->pemasok === null ? null : [
                'id' => $m->pemasok->id,
                'nama' => $m->pemasok->nama,
            ]),
            'dicatat_oleh' => $m->dicatat_oleh,
            'pencatat' => $this->whenLoaded('pencatat', fn () => $m->pencatat?->name),
            'catatan' => $m->catatan,
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }
}
