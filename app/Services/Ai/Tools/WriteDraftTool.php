<?php

namespace App\Services\Ai\Tools;

use App\Enums\AksiAiDraft;
use App\Models\AiDraft;
use App\Models\User;
use App\Services\Ai\Drafts\DraftService;
use App\Services\Ai\Drafts\DraftValidationResult;
use App\Services\Ai\Drafts\DraftValidator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Tool tulis (buat_..., ubah_..., hapus_...): TIDAK PERNAH menulis data modul. Alurnya:
 *   argumen tervalidasi skema -> argumen terlindungi dibuang -> target (ubah/hapus) dicari lewat
 *   SppgScope + Policy -> payload divalidasi dry-run dengan Form Request modul (DraftValidator)
 *   -> AiDraft pending (berlaku config ai.drafts.ttl_minutes) -> bot mengirim tombol Mini App.
 * Data baru tersimpan hanya saat pengguna menekan Simpan di Mini App (endpoint modul + ai_draft_id).
 *
 * Subkelas mengisi $name, $description, $module, $action (C/U/D), $model, lalu:
 *   formRequest(): kelas Form Request store/update modul (null untuk hapus)
 *   fields(): ['properties' => [...], 'required' => [...]] skema kolom form
 *   routeParameter(): nama parameter rute model untuk Form Request update (mis. 'titikLayanan')
 *   toPayload(): opsional, memetakan argumen ke kolom form (mis. nama pemasok -> pemasok_id)
 */
abstract class WriteDraftTool extends BaseAiTool
{
    protected string $action = 'C';

    final public function isWrite(): bool
    {
        return true;
    }

    /** @return class-string<FormRequest>|null */
    abstract protected function formRequest(): ?string;

    /**
     * @return array{properties: array<string, array<string, mixed>>, required?: list<string>}
     */
    abstract protected function fields(): array;

    protected function routeParameter(): ?string
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function toPayload(User $user, array $arguments): array
    {
        return $arguments;
    }

    /**
     * Hook opsional setelah validasi dry-run, sebelum draf disimpan: sesuaikan payload/keyakinan
     * (mis. kolom yang disimpulkan tool dari teks = keyakinan rendah, penanda sub-modul draf).
     * Tidak boleh menulis data modul. Bawaan: tidak mengubah apa pun.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, float>  $confidence
     * @param  array<string, list<string>>  $errors
     * @return array{0: array<string, mixed>, 1: array<string, float>} [payload, keyakinan]
     */
    protected function afterValidation(array $payload, array $confidence, array $errors): array
    {
        return [$payload, $confidence];
    }

    /** Cari record target lewat query ber-SppgScope (null = tidak ditemukan / SPPG lain). */
    protected function findTarget(User $user, int $id): ?Model
    {
        return $this->model === null ? null : $this->model::query()->find($id);
    }

    public function draftAction(): AksiAiDraft
    {
        return match ($this->action) {
            'U' => AksiAiDraft::Update,
            'D' => AksiAiDraft::Delete,
            default => AksiAiDraft::Create,
        };
    }

    public function parameters(): array
    {
        $fields = $this->fields();
        $properties = $fields['properties'];
        $required = $fields['required'] ?? [];

        if ($this->action !== 'C') {
            $properties = ['target_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'ID data yang akan diubah/dihapus (dari tool cari_*).']] + $properties;
            $required = array_values(array_unique(array_merge(['target_id'], $required)));
        }

        $properties['keyakinan'] = [
            'type' => 'object',
            'description' => 'Opsional. Keyakinan 0-1 per nama kolom yang Anda isi (mis. {"nama": 0.9}). Beri nilai rendah bila tulisan nota/suara kurang jelas.',
            'additionalProperties' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
        ];

        return ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false];
    }

    /**
     * Draf butuh SPPG (ai_drafts.sppg_id wajib): pengguna tanpa SPPG (Admin) memakai Mini App langsung.
     * Investor (R-06a) baca saja: tidak pernah ditawari tool tulis walau matriks salah isi.
     */
    public function isAvailableFor(User $user): bool
    {
        return $user->sppg_id !== null && ! $user->isInvestor() && parent::isAvailableFor($user);
    }

    /**
     * Validasi ulang draf tersimpan (GET /ai-drafts/{uuid}) dengan aturan Form Request terkini.
     * Null bila modul tidak memakai Form Request (hapus).
     */
    public function revalidate(User $user, AiDraft $draft): ?DraftValidationResult
    {
        if ($this->formRequest() === null) {
            return null;
        }

        $routeParameters = [];

        if ($draft->target_id !== null && $this->routeParameter() !== null) {
            $target = $this->findTarget($user, (int) $draft->target_id);

            if ($target === null) {
                return new DraftValidationResult(false);
            }

            $routeParameters = [$this->routeParameter() => $target];
        }

        return app(DraftValidator::class)->validate($this->formRequest(), (array) $draft->payload, $user, $routeParameters, (array) $draft->confidence);
    }

    final public function execute(User $user, array $arguments, ToolContext $context): ToolResult
    {
        if (! $this->isAvailableFor($user)) {
            return ToolResult::denied();
        }

        $confidence = is_array($arguments['keyakinan'] ?? null) ? $arguments['keyakinan'] : [];
        unset($arguments['keyakinan']);

        $target = null;

        if ($this->action !== 'C') {
            $target = $this->findTarget($user, (int) ($arguments['target_id'] ?? 0));
            unset($arguments['target_id']);

            if ($target === null) {
                return ToolResult::invalid('Data yang dimaksud tidak ditemukan.');
            }

            if (! $this->allows($user, $this->action === 'U' ? 'update' : 'delete', $target)) {
                return ToolResult::denied('Anda tidak berhak mengubah/menghapus data ini.');
            }
        }

        [$payload] = ArgumentValidator::stripProtected(
            $this->toPayload($user, $arguments),
            (array) config('ai.protected_arguments', []),
        );

        $errors = [];
        $scores = [];

        if ($this->formRequest() !== null) {
            $routeParameters = $target !== null && $this->routeParameter() !== null ? [$this->routeParameter() => $target] : [];
            $result = app(DraftValidator::class)->validate($this->formRequest(), $payload, $user, $routeParameters, $confidence);

            if (! $result->authorized) {
                return ToolResult::denied();
            }

            $errors = $result->errors;
            $scores = $result->confidence;
        }

        [$payload, $scores] = $this->afterValidation($payload, $scores, $errors);

        $draft = app(DraftService::class)->create(
            user: $user,
            module: $this->module,
            action: $this->draftAction(),
            payload: $payload,
            confidence: $scores,
            interactionId: $context->interactionId,
            targetId: $target?->getKey(),
        );

        return ToolResult::draft($draft, $errors);
    }
}
