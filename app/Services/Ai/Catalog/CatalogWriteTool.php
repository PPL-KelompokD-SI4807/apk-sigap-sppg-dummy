<?php

namespace App\Services\Ai\Catalog;

use App\Models\User;
use App\Services\Ai\Tools\WriteDraftTool;
use App\Support\Rbac;
use Illuminate\Database\Eloquent\Model;

/**
 * Tool tulis generik `buat_/ubah_/hapus_<sumber_daya>` dari satu ResourceSpec. TIDAK menulis data modul:
 * hanya AiDraft tervalidasi dry-run dengan Form Request modul (aturan didefinisikan sekali).
 *
 * - Skema argumen diturunkan dari rules() Form Request (RulesSchema) sehingga perubahan field Form Request
 *   otomatis ikut; `sppg_id`, `user_id`, `role`, `created_by`, ... tidak pernah ditawarkan dan selalu dibuang.
 * - Target ubah/hapus dicari lewat query ber-SppgScope + pembatasan SPPG eksplisit, lalu Policy update/delete.
 * - Untuk slug modul yang dipakai >1 sumber daya (mis. keuangan: pencairan/pengeluaran), draf ditandai
 *   `_resource` agar GET /ai-drafts/{uuid} memvalidasi ulang dengan Form Request yang benar (DraftToolResolver).
 */
class CatalogWriteTool extends WriteDraftTool
{
    public const RESERVED_KEYS = ['_resource', '_peringatan'];

    /** @param  'C'|'U'|'D'  $kind */
    public function __construct(protected readonly ResourceSpec $spec, string $kind)
    {
        $this->action = $kind;
        $this->module = $spec->module;
        $this->model = $spec->model;
        $this->name = $spec->toolName($kind);

        $this->description = match ($kind) {
            'C' => "Siapkan DRAF {$spec->label} baru untuk diperiksa pengguna di Mini App. Data BELUM tersimpan sampai pengguna menekan Simpan. Isi hanya kolom yang disebut pengguna; ID data master diambil dari tool cari_*.",
            'U' => "Siapkan DRAF perubahan {$spec->label}. target_id = ID data dari tool {$spec->toolName('R')}. Isi hanya kolom yang diubah. Data BELUM berubah sampai pengguna menyimpan di Mini App.",
            default => "Siapkan DRAF penghapusan {$spec->label}. target_id = ID data dari tool {$spec->toolName('R')}. Data baru terhapus setelah pengguna mengonfirmasi eksplisit di Mini App.",
        };
    }

    public function spec(): ResourceSpec
    {
        return $this->spec;
    }

    /** Penanda sub-modul pada draf (null = sumber daya bawaan modul). */
    public function draftResource(): ?string
    {
        return $this->spec->marker;
    }

    protected function formRequest(): ?string
    {
        return match ($this->action) {
            'C' => $this->spec->store,
            'U' => $this->spec->update,
            default => null,
        };
    }

    protected function routeParameter(): ?string
    {
        return $this->action === 'C' ? null : $this->spec->routeParameter;
    }

    protected function fields(): array
    {
        $request = $this->formRequest();

        if ($request === null) {
            return ['properties' => ['alasan' => ['type' => 'string', 'maxLength' => 255, 'description' => 'Alasan penghapusan (opsional).']], 'required' => []];
        }

        return RulesSchema::derive(
            $request,
            $this->routeParameter(),
            $this->routeParameter() === null ? null : $this->spec->model,
            $this->spec->exclude,
            $this->spec->hints,
        );
    }

    public function parameters(): array
    {
        $parameters = parent::parameters();

        if ($this->action === 'D') {
            unset($parameters['properties']['keyakinan']);
        }

        return $parameters;
    }

    public function isAvailableFor(User $user): bool
    {
        if (! parent::isAvailableFor($user)) {
            return false;
        }

        return $this->spec->jenis === null || Rbac::canForJenis($user, $this->module, $this->action, $this->spec->jenis);
    }

    /** `versi` tidak pernah diterima dari LLM. */
    protected function toPayload(User $user, array $arguments): array
    {
        if ($this->spec->berversi) {
            unset($arguments['versi']);
        }

        return $arguments;
    }

    /** Draf ubah record berversi: versi diambil dari record target saat draf dibuat (konfirmasi basi -> 409). */
    protected function sisipkanDariServer(array $payload, ?Model $target): array
    {
        if ($this->spec->berversi && $this->action === 'U' && $target !== null) {
            $payload['versi'] = (int) $target->getAttribute('versi');
        }

        return $payload;
    }

    protected function findTarget(User $user, int $id): ?Model
    {
        return SppgBound::apply($this->spec->model::query(), $user, $this->spec->scopeColumn)->whereKey($id)->first();
    }

    protected function afterValidation(array $payload, array $confidence, array $errors): array
    {
        if ($this->draftResource() !== null) {
            $payload['_resource'] = $this->draftResource();
        }

        return [$payload, $confidence];
    }
}
