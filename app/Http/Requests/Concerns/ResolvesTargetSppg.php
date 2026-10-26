<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;

/**
 * SPPG tujuan untuk modul ber-tenant: Admin (lintas SPPG) wajib mengirim sppg_id saat membuat;
 * peran lain selalu memakai SPPG-nya dan sppg_id dari body diabaikan (BelongsToSppg memaksa).
 */
trait ResolvesTargetSppg
{
    /** @return list<mixed> */
    protected function sppgRule(): array
    {
        if (! $this->user()?->isAdmin()) {
            return [];
        }

        return ['required', 'integer', Rule::exists('sppgs', 'id')->whereNull('deleted_at')];
    }

    /** SPPG tempat record dibuat. */
    protected function targetSppgId(): int
    {
        $user = $this->user();

        return (int) ($user?->isAdmin() ? $this->input('sppg_id') : $user?->sppg_id);
    }

    /** Non-admin tanpa SPPG tidak dapat menulis data tenant (fail closed). */
    protected function hasSppgContext(): bool
    {
        $user = $this->user();

        return $user !== null && ($user->isAdmin() || $user->sppg_id !== null);
    }
}
