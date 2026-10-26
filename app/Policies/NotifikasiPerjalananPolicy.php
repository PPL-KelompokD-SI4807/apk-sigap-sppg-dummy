<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\NotifikasiPerjalanan;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Modul 2 Notifikasi Perjalanan: admin R; kepala_sppg CR; relawan (unit distribusi) CR.
 *
 * - Relawan hanya melihat dan mengelola perjalanan yang ia picu sendiri (kolom dipicu_oleh).
 * - Tidak ada U/D pada matriks. Perubahan status perjalanan adalah kelanjutan dari "memicu
 *   notifikasi" sehingga memakai ability `ubahStatus` yang mensyaratkan hak C.
 * - Relawan unit selain distribusi ditolak oleh unit_rules (huruf kosong).
 */
class NotifikasiPerjalananPolicy extends BaseSppgPolicy
{
    protected string $module = 'notifikasi-perjalanan';

    protected ?string $ownerColumn = 'dipicu_oleh';

    public function view(User $user, Model $model): bool
    {
        return parent::view($user, $model) && $this->milikSendiriBilaRelawan($user, $model);
    }

    public function ubahStatus(User $user, NotifikasiPerjalanan $notifikasi): bool
    {
        return $this->canLetter($user, 'C')
            && $this->sameSppg($user, $notifikasi)
            && $this->milikSendiriBilaRelawan($user, $notifikasi);
    }

    private function milikSendiriBilaRelawan(User $user, Model $model): bool
    {
        return $user->role !== Role::Relawan || $this->isOwnRecord($user, $model);
    }
}
