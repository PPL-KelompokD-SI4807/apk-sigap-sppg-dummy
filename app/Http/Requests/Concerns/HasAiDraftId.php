<?php

namespace App\Http\Requests\Concerns;

/**
 * Field opsional ai_draft_id (uuid draf Agen AI) pada store/update. Keabsahan draf
 * (milik pengguna, modul sama, pending, belum kedaluwarsa) diperiksa AiDraftConfirmer
 * di dalam transaksi controller.
 */
trait HasAiDraftId
{
    /** @return array<string, list<string>> */
    protected function aiDraftRules(): array
    {
        return ['ai_draft_id' => ['nullable', 'uuid', 'exists:ai_drafts,uuid']];
    }
}
