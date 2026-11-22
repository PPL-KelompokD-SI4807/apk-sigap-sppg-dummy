<?php

namespace App\Policies;

use App\Models\Menu;
use App\Models\User;
use App\Support\Rbac;

/**
 * Modul 8 Menu dan Gizi: ahli_gizi CRUD; admin/kepala/staf_gudang/relawan baca.
 * Mengajukan menu ke investor (R-06b-1) = hak C modul 'persetujuan-menu' (ahli_gizi saja) di SPPG sendiri.
 */
class MenuPolicy extends BaseSppgPolicy
{
    protected string $module = 'menu-gizi';

    /**
     * Rincian biaya memuat harga pemasok: wajib bisa melihat menu SPPG-nya DAN punya hak R pada
     * `persetujuan-menu`, atau hak R pada `pemasok` + `menu-gizi` (peran yang memang boleh melihat harga).
     */
    public function lihatRincianBiaya(User $user, Menu $menu): bool
    {
        return $this->view($user, $menu)
            && (Rbac::can($user, 'persetujuan-menu', 'R')
                || (Rbac::can($user, 'pemasok', 'R') && Rbac::can($user, 'menu-gizi', 'R')));
    }

    public function ajukan(User $user, Menu $menu): bool
    {
        return Rbac::can($user, 'persetujuan-menu', 'C')
            && ! $user->isAdmin()
            && $user->sppg_id !== null
            && (int) $menu->sppg_id === (int) $user->sppg_id;
    }
}
