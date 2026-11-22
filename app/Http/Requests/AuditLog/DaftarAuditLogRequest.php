<?php

namespace App\Http\Requests\AuditLog;

use App\Models\AuditLog;
use App\Support\ModulAudit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filter GET /api/v1/audit-log. Otorisasi (AuditLogPolicy::viewAny, hanya Admin) dijalankan SEBELUM validasi,
 * sehingga peran lain selalu 403 dan bukan 422.
 */
class DaftarAuditLogRequest extends FormRequest
{
    public const PER_PAGE_BAWAAN = 25;

    public const PER_PAGE_MAKS = 100;

    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', AuditLog::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'modul' => ['nullable', 'string', Rule::in(ModulAudit::slugs())],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'aksi' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** per_page dibatasi 100 (nilai lebih besar diturunkan, bukan ditolak). */
    public function perPage(): int
    {
        return min((int) ($this->validated('per_page') ?? self::PER_PAGE_BAWAAN), self::PER_PAGE_MAKS);
    }
}
