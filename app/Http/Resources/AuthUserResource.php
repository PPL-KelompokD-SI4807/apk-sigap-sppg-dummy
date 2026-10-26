<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Support\Rbac;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * Profil pengguna login + hak akses efektif (dipakai Mini App untuk menu & tombol).
 *
 * @mixin User
 */
class AuthUserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'role' => $user->role->value,
            'role_label' => $user->role->label(),
            'unit' => $user->unit?->value,
            'sppg_id' => $user->sppg_id,
            'sppg_name' => $this->sppgName($user),
            'telegram_username' => $user->telegram_username,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'modules' => Rbac::allowedModules($user),
            'permissions' => Rbac::permissions($user),
        ];
    }

    private function sppgName(User $user): ?string
    {
        if ($user->sppg_id === null) {
            return null;
        }

        $name = DB::table('sppgs')->where('id', $user->sppg_id)->value('nama');

        return $name === null ? null : (string) $name;
    }
}
