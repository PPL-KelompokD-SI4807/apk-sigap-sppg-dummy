<?php

namespace App\Services\Ai;

use App\Enums\AksiAiDraft;
use App\Enums\StatusAiDraft;
use App\Models\AiDraft;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Menandai AiDraft "confirmed" setelah aksi tulis (store/update) dari draf berhasil.
 *
 * Dipanggil controller modul DI DALAM transaksi aksi tulisnya, sehingga bila draf tidak sah
 * (milik pengguna lain, modul berbeda, sudah dipakai, atau kedaluwarsa) seluruh aksi dibatalkan
 * dengan 422 pada field `ai_draft_id`.
 */
class AiDraftConfirmer
{
    /**
     * Aksi draf harus cocok dengan aksi endpoint: tanpa $targetId = store (draf create, tanpa target),
     * dengan $targetId = update (draf update untuk record yang sama). $action menimpa tebakan ini.
     * $resource = penanda sub-modul endpoint (payload `_resource`, mis. 'pengeluaran'); null = sumber
     * daya bawaan modul. Draf pencairan tidak dapat dikonfirmasi lewat endpoint pengeluaran, dst.
     */
    public function confirm(?string $uuid, User $user, string $module, ?int $targetId = null, ?AksiAiDraft $action = null, ?string $resource = null): ?AiDraft
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        // Investor baca saja (R-06a): tidak pernah mengonfirmasi draf (lapis kedua selain Policy modul).
        if ($user->isInvestor()) {
            throw ValidationException::withMessages([
                'ai_draft_id' => ['Draf AI tidak ditemukan, sudah dipakai, atau kedaluwarsa.'],
            ]);
        }

        $action ??= $targetId === null ? AksiAiDraft::Create : AksiAiDraft::Update;
        $draft = AiDraft::query()->where('uuid', $uuid)->lockForUpdate()->first();

        if ($draft === null
            || (int) $draft->user_id !== (int) $user->getKey()
            || $draft->module !== $module
            || ! $draft->bisaDikonfirmasi()
            || $draft->action !== $action
            || (((array) $draft->payload)['_resource'] ?? null) !== $resource
            || ($draft->target_id === null ? null : (int) $draft->target_id) !== $targetId) {
            throw ValidationException::withMessages([
                'ai_draft_id' => ['Draf AI tidak ditemukan, sudah dipakai, atau kedaluwarsa.'],
            ]);
        }

        $draft->forceFill(['status' => StatusAiDraft::Confirmed, 'confirmed_at' => now()])->save();

        return $draft;
    }
}
