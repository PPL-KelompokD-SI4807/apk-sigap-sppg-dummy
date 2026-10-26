<?php

namespace App\Models\Concerns;

use App\Models\Scopes\SppgScope;
use App\Models\Sppg;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Isolasi data per SPPG (multi-tenant ringan).
 *
 * - Global scope SppgScope (lihat kelas tersebut untuk aturan admin/console).
 * - Saat creating oleh non-admin: sppg_id SELALU dipaksa = sppg_id pengguna, apa pun isi
 *   request body. Saat updating oleh non-admin: perubahan sppg_id dikembalikan ke nilai asal.
 *   Admin boleh mengisi sppg_id bebas (wajib divalidasi Form Request).
 * - Relasi sppg() dan scope ofSppg($id) untuk console/queue (tanpa pengguna login).
 */
trait BelongsToSppg
{
    public static function bootBelongsToSppg(): void
    {
        static::addGlobalScope(new SppgScope);

        static::creating(function ($model): void {
            $user = SppgScope::currentUser();

            if ($user !== null && ! $user->isAdmin()) {
                $model->setAttribute('sppg_id', $user->sppg_id);
            }
        });

        static::updating(function ($model): void {
            $user = SppgScope::currentUser();

            if ($user !== null && ! $user->isAdmin() && $model->isDirty('sppg_id')) {
                $model->setAttribute('sppg_id', $model->getOriginal('sppg_id'));
            }
        });
    }

    public function initializeBelongsToSppg(): void
    {
        $this->mergeCasts(['sppg_id' => 'integer']);
    }

    public function sppg(): BelongsTo
    {
        return $this->belongsTo(Sppg::class, 'sppg_id');
    }

    /**
     * Filter eksplisit per SPPG (untuk job/scheduler/admin).
     */
    public function scopeOfSppg(Builder $query, ?int $sppgId): Builder
    {
        return $query->where($this->qualifyColumn('sppg_id'), $sppgId);
    }
}
