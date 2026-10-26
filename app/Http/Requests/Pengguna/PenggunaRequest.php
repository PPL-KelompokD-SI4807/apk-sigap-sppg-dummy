<?php

namespace App\Http\Requests\Pengguna;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Http\Requests\Concerns\HasAiDraftId;
use App\Models\User;
use App\Rules\NomorTelepon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Aturan validasi pengguna (didefinisikan sekali; Store/Update memakainya).
 * `telegram_id` sengaja tidak ada: hanya dapat diisi lewat undangan Telegram.
 */
abstract class PenggunaRequest extends FormRequest
{
    use HasAiDraftId;

    abstract protected function creating(): bool;

    /** Pengguna yang sedang diubah (null saat membuat). */
    protected function target(): ?User
    {
        return null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $req = $this->creating() ? 'required' : 'sometimes';

        return [
            'name' => [$req, 'string', 'max:120'],
            'role' => [$req, Rule::enum(Role::class)],
            'unit' => ['nullable', Rule::enum(RelawanUnit::class)],
            'sppg_id' => ['nullable', 'integer', Rule::exists('sppgs', 'id')->whereNull('deleted_at')],
            'phone' => ['nullable', new NomorTelepon],
            'is_active' => ['sometimes', 'boolean'],
        ] + $this->aiDraftRules();
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $target = $this->target();
            $role = Role::tryFrom((string) $this->input('role', $target?->role->value));
            $roleChanged = $target !== null && $this->has('role') && $role !== $target->role;

            if ($role === null) {
                return;
            }

            $unit = $this->has('unit') ? $this->input('unit') : ($roleChanged ? null : $target?->unit?->value);
            $sppgId = $this->has('sppg_id') ? $this->input('sppg_id') : ($role === Role::Admin ? null : $target?->sppg_id);

            if ($role === Role::Relawan && $unit === null) {
                $validator->errors()->add('unit', 'Unit tugas (dapur/distribusi) wajib untuk relawan.');
            }

            if ($role !== Role::Relawan && $this->filled('unit')) {
                $validator->errors()->add('unit', 'Unit tugas hanya untuk peran relawan.');
            }

            if ($role === Role::Admin && $this->filled('sppg_id')) {
                $validator->errors()->add('sppg_id', 'Admin bersifat lintas SPPG; sppg_id harus kosong.');
            }

            if ($role !== Role::Admin && $sppgId === null) {
                $validator->errors()->add('sppg_id', 'SPPG wajib diisi untuk peran selain admin.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'role.enum' => 'Peran tidak dikenal.',
            'unit.enum' => 'Unit tugas harus dapur atau distribusi.',
            'sppg_id.exists' => 'SPPG tidak ditemukan.',
        ];
    }
}
