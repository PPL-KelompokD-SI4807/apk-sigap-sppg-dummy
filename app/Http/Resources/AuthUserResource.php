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
 * R-06a: `sppg_aktif_id` = SPPG tempat data dibatasi pada permintaan ini (investor: dari header
 * X-SPPG-Id tervalidasi / SPPG satu-satunya; null = belum dipilih atau admin). `multi_sppg` = true
 * bila pengguna wajib memilih SPPG aktif lewat GET /sppg-saya. `baca_saja` = tanpa hak C/U/D di
 * modul mana pun. `agregat` = modul yang hanya boleh dibaca agregatnya (mis. penerima-manfaat).
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
            'sppg_aktif_id' => $user->sppgAktifId(),
            'multi_sppg' => $user->isInvestor(),
            'telegram_username' => $user->telegram_username,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'modules' => Rbac::allowedModules($user),
            'permissions' => Rbac::permissions($user),
            'baca_saja' => Rbac::readOnly($user),
            'agregat' => Rbac::aggregateOnlyModules($user),
            'keputusan' => Rbac::decisionModules($user),
        ];
    }

    private function sppgName(User $user): ?string
    {
        $sppgId = $user->sppgAktifId();

        if ($sppgId === null) {
            return null;
        }

        $name = DB::table('sppgs')->where('id', $sppgId)->value('nama');

        return $name === null ? null : (string) $name;
    }
}
