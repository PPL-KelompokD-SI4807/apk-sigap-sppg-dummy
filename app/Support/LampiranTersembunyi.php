<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\User;

/**
 * Lampiran modul yang disembunyikan dari peran tertentu walau peran itu boleh membaca record induknya
 * (R06a-S-05: foto keluhan/mutu dapat memuat wajah atau nama anak; investor tidak melihatnya).
 * Sumber: config('unggahan.modul.<slug>.sembunyikan_dari_peran'). Teks record tetap terlihat.
 */
final class LampiranTersembunyi
{
    public const ALASAN = 'lampiran_disembunyikan';

    public static function untuk(?User $user, ?string $slugModul): bool
    {
        if ($user === null || $slugModul === null || ! $user->role instanceof Role) {
            return false;
        }

        return in_array($user->role->value, (array) config("unggahan.modul.{$slugModul}.sembunyikan_dari_peran", []), true);
    }
}
