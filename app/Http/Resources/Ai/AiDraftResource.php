<?php

namespace App\Http\Resources\Ai;

use App\Models\AiDraft;
use App\Services\Ai\Drafts\GalatDraf;
use App\Services\Ai\Referensi\PilihanReferensiDraf;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Draf Agen AI untuk prefill formulir Mini App. `errors` = galat per kolom yang dicatat server saat draf dibuat
 * (GalatDraf: satuan salah, rujukan tidak ditemukan, galat skema, ...) digabung hasil dry-run Form Request terbaru
 * (bila dihitung controller); `perlu_diperbaiki` = true bila `errors` tidak kosong. `form` = rute Mini App tujuan.
 * Untuk update, payload.id = target_id. Kunci internal (`_pilihan`, `_galat`) tidak disajikan mentah.
 *
 * @mixin AiDraft
 */
class AiDraftResource extends JsonResource
{
    /** @var array<string, list<string>>|null */
    public ?array $errors = null;

    /**
     * Rujukan nama yang masih ambigu (PilihanReferensiDraf::untukTampilan), diisi controller pada GET /ai-drafts/{uuid}.
     *
     * @var list<array<string, mixed>>
     */
    public array $pilihan = [];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var AiDraft $draft */
        $draft = $this->resource;
        // Pilihan tertunda internal (hanya ID kandidat) tidak disajikan mentah; lihat `pilihan_referensi`.
        $payload = array_diff_key((array) $draft->payload, [PilihanReferensiDraf::KUNCI => true, GalatDraf::KUNCI => true]);
        $errors = $this->semuaGalat();

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
            'perlu_dicek' => $this->needsReview($errors),
            'perlu_diperbaiki' => $errors !== [],
            'errors' => (object) $errors,
            'pilihan_referensi' => $this->pilihan,
            'status' => $draft->status->value,
            'expires_at' => $draft->expires_at?->toIso8601String(),
            'created_at' => $draft->created_at?->toIso8601String(),
            'form' => $form,
        ];
    }

    /** @return array<string, list<string>> galat server tersimpan (didahulukan) + galat dry-run terbaru */
    private function semuaGalat(): array
    {
        return GalatDraf::gabung(GalatDraf::dari($this->resource), $this->errors ?? []);
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return list<string>
     */
    private function needsReview(array $errors): array
    {
        $threshold = (float) config('ai.drafts.low_confidence', 0.7);
        $fields = array_keys(array_filter((array) ($this->resource->confidence ?? []), fn ($c) => (float) $c < $threshold));

        foreach (array_keys($errors) as $key) {
            $fields[] = explode('.', (string) $key)[0];
        }

        return array_values(array_unique(array_map('strval', $fields)));
    }
}
