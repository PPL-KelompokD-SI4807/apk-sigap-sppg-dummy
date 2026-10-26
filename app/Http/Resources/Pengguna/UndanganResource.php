<?php

namespace App\Http\Resources\Pengguna;

use App\Models\Invitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Undangan. Tidak pernah memuat token maupun token_hash.
 *
 * @mixin Invitation
 */
class UndanganResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Invitation $inv */
        $inv = $this->resource;

        return [
            'id' => $inv->id,
            'tipe' => $inv->tipe->value,
            'status' => match (true) {
                $inv->revoked_at !== null => 'dicabut',
                $inv->used_at !== null => 'terpakai',
                $inv->expires_at->isPast() => 'kedaluwarsa',
                default => 'aktif',
            },
            'role' => $inv->role,
            'unit' => $inv->unit,
            'sppg_id' => $inv->sppg_id,
            'user_id' => $inv->user_id,
            'user_nama' => $this->whenLoaded('user', fn () => $inv->user?->name),
            'titik_layanan_id' => $inv->titik_layanan_id,
            'titik_layanan_nama' => $this->whenLoaded('titikLayanan', fn () => $inv->titikLayanan?->nama),
            'created_by' => $inv->created_by,
            'expires_at' => $inv->expires_at->toIso8601String(),
            'used_at' => $inv->used_at?->toIso8601String(),
            'revoked_at' => $inv->revoked_at?->toIso8601String(),
            'created_at' => $inv->created_at?->toIso8601String(),
        ];
    }
}
