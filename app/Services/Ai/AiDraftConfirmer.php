<?php

namespace App\Services\Ai;

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
    public function confirm(?string $uuid, User $user, string $module, ?int $targetId = null): ?AiDraft
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        $draft = AiDraft::query()->where('uuid', $uuid)->lockForUpdate()->first();

        if ($draft === null
            || (int) $draft->user_id !== (int) $user->getKey()
            || $draft->module !== $module
            || ! $draft->bisaDikonfirmasi()
            || ($draft->target_id !== null && $targetId !== null && (int) $draft->target_id !== $targetId)) {
            throw ValidationException::withMessages([
                'ai_draft_id' => ['Draf AI tidak ditemukan, sudah dipakai, atau kedaluwarsa.'],
            ]);
        }

        $draft->forceFill(['status' => StatusAiDraft::Confirmed, 'confirmed_at' => now()])->save();

        return $draft;
    }
}
