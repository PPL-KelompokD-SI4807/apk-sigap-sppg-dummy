<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Rbac;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Policy dasar: matriks CRUD Tabel 5 (config/rbac.php) + isolasi SPPG.
 *
 * Cara pakai di modul:
 *   class BahanBakuPolicy extends BaseSppgPolicy { protected string $module = 'bahan-baku'; }
 *
 * Aturan:
 * - Non-admin hanya boleh mengakses model dengan sppg_id sama dengan miliknya; admin lintas SPPG.
 *   Investor: sama dengan SATU SPPG aktif tervalidasi (User::sppgAktifId(), wajib miliknya).
 * - Huruf bertanda "*" (R*) hanya berlaku untuk record milik sendiri; viewAny ditolak.
 * - Relawan (owner_rules): update/delete hanya record buatannya sendiri ($ownerColumn) dan
 *   dibuat hari ini (zona waktu aplikasi). Bila kolom pemilik tidak ada di model -> ditolak.
 *   Set $ownerColumn = null hanya bila modul memang tidak menyimpan pembuat.
 * - $jenis: sub-jenis data (mis. JadwalDistribusiPolicy -> 'distribusi') untuk aturan unit
 *   relawan (jadwal: dapur -> produksi, distribusi -> distribusi).
 * - forceDelete selalu ditolak (data audit hanya dihapus lunak).
 * - Model yang kolom SPPG-nya bukan sppg_id (mis. Sppg memakai id) override sppgIdOf().
 */
abstract class BaseSppgPolicy
{
    /** Slug modul pada config/rbac.php. */
    protected string $module;

    /** Kolom pembuat record untuk aturan kepemilikan relawan. */
    protected ?string $ownerColumn = 'created_by';

    /** Sub-jenis data untuk unit_rules 'jenis' (null = tidak relevan). */
    protected ?string $jenis = null;

    public function viewAny(User $user): bool
    {
        return $this->canLetter($user, 'R') && ! Rbac::isSelfOnly($user, $this->module, 'R');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->allows($user, $model, 'R');
    }

    public function create(User $user): bool
    {
        return $this->canLetter($user, 'C') && ! Rbac::isSelfOnly($user, $this->module, 'C');
    }

    public function update(User $user, Model $model): bool
    {
        return $this->allows($user, $model, 'U');
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->allows($user, $model, 'D');
    }

    public function restore(User $user, Model $model): bool
    {
        return $this->allows($user, $model, 'D');
    }

    public function forceDelete(User $user, Model $model): bool
    {
        return false;
    }

    protected function canLetter(User $user, string $letter): bool
    {
        return $this->jenis === null
            ? Rbac::can($user, $this->module, $letter)
            : Rbac::canForJenis($user, $this->module, $letter, $this->jenis);
    }

    protected function allows(User $user, Model $model, string $letter): bool
    {
        if (! $this->canLetter($user, $letter) || ! $this->sameSppg($user, $model)) {
            return false;
        }

        if (Rbac::isSelfOnly($user, $this->module, $letter)) {
            return $this->isOwnRecord($user, $model);
        }

        if (Rbac::ownerRule($user, $letter) === 'own_same_day') {
            return $this->isOwnRecord($user, $model) && $this->createdToday($model);
        }

        return true;
    }

    protected function sameSppg(User $user, Model $model): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $sppgId = $this->sppgIdOf($model);
        $aktif = $user->sppgAktifId();

        return $aktif !== null && $sppgId !== null && $sppgId === $aktif;
    }

    protected function sppgIdOf(Model $model): ?int
    {
        $value = $model->getAttribute('sppg_id');

        return $value === null ? null : (int) $value;
    }

    protected function isOwnRecord(User $user, Model $model): bool
    {
        if ($model instanceof User) {
            return $model->is($user);
        }

        if ($this->ownerColumn === null) {
            return true;
        }

        if (! array_key_exists($this->ownerColumn, $model->getAttributes())) {
            return false;
        }

        return (int) $model->getAttribute($this->ownerColumn) === (int) $user->getKey();
    }

    protected function createdToday(Model $model): bool
    {
        $createdAt = $model->getAttribute($model->getCreatedAtColumn() ?? 'created_at');

        return $createdAt !== null && Carbon::parse($createdAt)->isToday();
    }
}
