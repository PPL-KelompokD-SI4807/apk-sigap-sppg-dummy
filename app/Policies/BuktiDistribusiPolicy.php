<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Modul 13 Bukti Distribusi (Tabel 5): admin R, kepala_sppg CRUD, ahli_gizi R, akuntan R,
 * staf_gudang -, relawan unit distribusi CRU. Relawan hanya membaca bukti yang ia catat atau
 * untuk jadwal yang ditugaskan kepadanya, dan mengubah bukti buatannya sendiri pada hari yang
 * sama (owner_rules, kolom dicatat_oleh). Daftar disaring serupa di controller.
 */
class BuktiDistribusiPolicy extends BaseSppgPolicy
{
    protected string $module = 'bukti-distribusi';

    protected ?string $ownerColumn = 'dicatat_oleh';

    public function view(User $user, Model $model): bool
    {
        if (! parent::view($user, $model)) {
            return false;
        }

        if ($user->role !== Role::Relawan || $this->isOwnRecord($user, $model)) {
            return true;
        }

        $petugas = $model->getAttribute('jadwalDistribusi')?->petugas_id;

        return $petugas !== null && (int) $petugas === (int) $user->getKey();
    }
}
