<?php

namespace App\Http\Resources;

use App\Models\PermintaanResetPassword;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Permintaan reset password (R-03). `pengguna` hanya untuk tampilan Admin (whenLoaded) dan tidak
 * memuat no telepon atau identitas Telegram.
 *
 * @mixin PermintaanResetPassword
 */
class PermintaanResetResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var PermintaanResetPassword $p */
        $p = $this->resource;

        return [
            'id' => $p->id,
            'status' => $p->statusEfektif(),
            'dibuat_pada' => $p->created_at?->toIso8601String(),
            'kedaluwarsa_pada' => $p->kedaluwarsa_pada->toIso8601String(),
            'diputuskan_pada' => $p->diputuskan_pada?->toIso8601String(),
            'selesai_pada' => $p->selesai_pada?->toIso8601String(),
            'pengguna' => $this->whenLoaded('user', fn () => [
                'id' => $p->user->id,
                'name' => $p->user->name,
                'username' => $p->user->username,
                'role' => $p->user->role->value,
                'role_label' => $p->user->role->label(),
                'sppg_id' => $p->user->sppg_id,
            ]),
        ];
    }
}
