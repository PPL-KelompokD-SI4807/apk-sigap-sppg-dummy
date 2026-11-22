<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use App\Support\ModulAudit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu baris log audit untuk Admin. nilai_lama/nilai_baru disaring ulang saat ditampilkan (ModulAudit::saring)
 * sehingga kolom auditExclude/hidden/sensitif tidak pernah keluar, termasuk dari log lama. IP dan user agent
 * sengaja tidak ditampilkan.
 *
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var AuditLog $log */
        $log = $this->resource;

        return [
            'id' => $log->id,
            'waktu' => $log->created_at?->toIso8601String(),
            'pengguna' => $log->user_id === null ? null : [
                'id' => $log->user_id,
                'nama' => $log->relationLoaded('user') ? $log->user?->name : null,
            ],
            'sppg_id' => $log->sppg_id,
            'sumber' => $log->sumber,
            'modul' => ModulAudit::slug($log->auditable_type),
            'modul_label' => ModulAudit::label($log->auditable_type),
            'rekaman_id' => $log->auditable_id,
            'aksi' => $log->event,
            'nilai_lama' => ModulAudit::saring($log->old_values, $log->auditable_type),
            'nilai_baru' => ModulAudit::saring($log->new_values, $log->auditable_type),
        ];
    }
}
