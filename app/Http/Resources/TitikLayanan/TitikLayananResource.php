<?php

namespace App\Http\Resources\TitikLayanan;

use App\Models\TitikLayanan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Titik layanan. pic_telegram_chat_id tidak diekspos (hanya penanda `pic_terhubung`).
 *
 * @mixin TitikLayanan
 */
class TitikLayananResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TitikLayanan $titik */
        $titik = $this->resource;

        return [
            'id' => $titik->id,
            'sppg_id' => $titik->sppg_id,
            'nama' => $titik->nama,
            'jenis' => $titik->jenis->value,
            'alamat' => $titik->alamat,
            'jarak_km' => $titik->jarak_km,
            'jumlah_sasaran' => $titik->jumlah_sasaran,
            'pic_nama' => $titik->pic_nama,
            'pic_telepon' => $titik->pic_telepon,
            'pic_terhubung' => $titik->picTerhubung(),
            'is_active' => $titik->is_active,
            'created_at' => $titik->created_at?->toIso8601String(),
            'updated_at' => $titik->updated_at?->toIso8601String(),
        ];
    }
}
