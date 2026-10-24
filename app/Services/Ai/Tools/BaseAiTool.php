<?php

namespace App\Services\Ai\Tools;

use App\Models\User;
use App\Support\Rbac;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Basis tool: ketersediaan diturunkan dari matriks Tabel 5 (Rbac, termasuk aturan unit relawan)
 * DAN Policy model ($model), sehingga tool untuk sel "–" tidak pernah ditawarkan ke LLM.
 *
 * Subkelas cukup mengisi properti:
 *   protected string $name = 'cari_titik_layanan';
 *   protected string $description = '...';
 *   protected string $module = 'titik-layanan';
 *   protected string $action = 'R';
 *   protected ?string $model = TitikLayanan::class;
 */
abstract class BaseAiTool implements AiTool
{
    protected string $name;

    protected string $description;

    protected string $module;

    /** C, R, U, atau D. */
    protected string $action;

    /** Kelas model untuk pengecekan Policy (viewAny/create, dan view/update/delete per record). */
    protected ?string $model = null;

    /** Izinkan sel bertanda "*" (hanya data sendiri, mis. pengguna R*). Default ditolak. */
    protected bool $allowSelfOnly = false;

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function module(): string
    {
        return $this->module;
    }

    public function action(): string
    {
        return $this->action;
    }

    public function isAvailableFor(User $user): bool
    {
        if (! $user->canAuthenticate() || ! Rbac::hasModule($this->module)) {
            return false;
        }

        if (! Rbac::can($user, $this->module, $this->action)) {
            return false;
        }

        if (Rbac::isSelfOnly($user, $this->module, $this->action) && ! $this->allowSelfOnly) {
            return false;
        }

        $ability = match ($this->action) {
            'R' => $this->allowSelfOnly ? null : 'viewAny',
            'C' => 'create',
            default => null,
        };

        if ($ability !== null && $this->model !== null) {
            return Gate::forUser($user)->allows($ability, $this->model);
        }

        return true;
    }

    /** Policy per record (view/update/delete) untuk pengguna tertentu. */
    protected function allows(User $user, string $ability, Model $record): bool
    {
        return Gate::forUser($user)->allows($ability, $record);
    }
}
