<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Modul 15 Feedback dan Keluhan: admin CRUD; kepala_sppg dan ahli_gizi RU (status & tindak lanjut,
 * dibatasi di Form Request); relawan CR. Relawan hanya membaca keluhan yang ia catat sendiri
 * (kolom dicatat_oleh); daftar disaring serupa di controller.
 */
class FeedbackKeluhanPolicy extends BaseSppgPolicy
{
    protected string $module = 'keluhan';

    protected ?string $ownerColumn = 'dicatat_oleh';

    public function view(User $user, Model $model): bool
    {
        return parent::view($user, $model)
            && ($user->role !== Role::Relawan || $this->isOwnRecord($user, $model));
    }
}
