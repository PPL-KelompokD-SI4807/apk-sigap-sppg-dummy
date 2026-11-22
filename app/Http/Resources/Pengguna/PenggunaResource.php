<?php

namespace App\Http\Resources\Pengguna;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pengguna. Tidak pernah memuat telegram_id (identitas Telegram mentah): hanya username dan
 * penanda `telegram_terhubung`.
 *
 * @mixin User
 */
class PenggunaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;
        $terhubung = $user->telegram_id !== null;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role->value,
            'role_label' => $user->role->label(),
            'unit' => $user->unit?->value,
            'sppg_id' => $user->sppg_id,
            'sppg_nama' => $this->whenLoaded('sppg', fn () => $user->sppg?->nama),
            'phone' => $user->phone,
            'telegram_username' => $user->telegram_username,
            'telegram_terhubung' => $terhubung,
            // R-03: username login dan penanda password sudah diatur (password tidak pernah tampil).
            'username' => $user->username,
            'password_diatur' => $user->punyaPassword(),
            'is_active' => $user->is_active,
            'status' => ! $user->is_active ? 'nonaktif' : ($terhubung ? 'aktif' : 'menunggu_telegram'),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
