<?php

namespace App\Http\Requests\Pengguna;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Undangan pengguna: hanya Admin (hak C modul pengguna). Kebijakan: proposal FR-02 memberi
 * wewenang mendaftarkan pengguna kepada Admin saja, sehingga Kepala SPPG tidak dapat mengundang.
 */
class StoreUndanganRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer', 'required_without:role', 'prohibits:role,unit,sppg_id',
                Rule::exists('users', 'id')->whereNull('deleted_at')],
            'role' => ['nullable', 'required_without:user_id', Rule::enum(Role::class)],
            'unit' => ['nullable', Rule::enum(RelawanUnit::class)],
            'sppg_id' => ['nullable', 'integer', Rule::exists('sppgs', 'id')->whereNull('deleted_at')],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function ($validator): void {
            if ($validator->errors()->isNotEmpty() || $this->filled('user_id')) {
                return;
            }

            $role = Role::from((string) $this->input('role'));

            if ($role === Role::Relawan && ! $this->filled('unit')) {
                $validator->errors()->add('unit', 'Unit tugas wajib untuk relawan.');
            }

            if ($role !== Role::Relawan && $this->filled('unit')) {
                $validator->errors()->add('unit', 'Unit tugas hanya untuk peran relawan.');
            }

            if ($role === Role::Admin && $this->filled('sppg_id')) {
                $validator->errors()->add('sppg_id', 'Admin bersifat lintas SPPG; sppg_id harus kosong.');
            }

            if ($role !== Role::Admin && ! $this->filled('sppg_id')) {
                $validator->errors()->add('sppg_id', 'SPPG wajib untuk peran selain admin.');
            }
        }];
    }
}
