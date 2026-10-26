<?php

namespace App\Http\Resources\Ai;

use App\Models\AiDraft;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Draf Agen AI untuk prefill formulir Mini App. `errors` = hasil dry-run Form Request terbaru
 * (bila dihitung controller), `form` = rute Mini App tujuan. Untuk update, payload.id = target_id.
 *
 * @mixin AiDraft
 */
class AiDraftResource extends JsonResource
{
    /** @var array<string, list<string>>|null */
    public ?array $errors = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var AiDraft $draft */
        $draft = $this->resource;
        $payload = (array) $draft->payload;

        if ($draft->target_id !== null) {
            $payload = ['id' => (int) $draft->target_id] + $payload;
        }

        $form = match ($draft->action->value) {
            'create' => "/{$draft->module}/baru",
            'update' => "/{$draft->module}/{$draft->target_id}/ubah",
            default => "/{$draft->module}/{$draft->target_id}",
        };

        return [
            'uuid' => $draft->uuid,
            'module' => $draft->module,
            'module_label' => (string) config("rbac.modules.{$draft->module}.label", $draft->module),
            'action' => $draft->action->value,
            'target_id' => $draft->target_id,
            'payload' => (object) $payload,
            'confidence' => (object) ($draft->confidence ?? []),
            'perlu_dicek' => $this->needsReview(),
            'errors' => (object) ($this->errors ?? []),
            'status' => $draft->status->value,
            'expires_at' => $draft->expires_at?->toIso8601String(),
            'created_at' => $draft->created_at?->toIso8601String(),
            'form' => $form,
        ];
    }

    /** @return list<string> */
    private function needsReview(): array
    {
        $threshold = (float) config('ai.drafts.low_confidence', 0.7);
        $fields = array_keys(array_filter((array) ($this->resource->confidence ?? []), fn ($c) => (float) $c < $threshold));

        foreach (array_keys($this->errors ?? []) as $key) {
            $fields[] = explode('.', (string) $key)[0];
        }

        return array_values(array_unique(array_map('strval', $fields)));
    }
}
