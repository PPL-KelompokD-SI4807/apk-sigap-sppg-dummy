<?php

namespace App\Http\Requests\Pengguna;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * PUT /pengguna/{user}/kepemilikan-sppg (R-06a): ganti seluruh daftar SPPG milik investor.
 * Hanya Admin (UserPolicy::kelolaKepemilikan); target wajib berperan investor. sppg_ids kosong
 * = investor tidak memiliki SPPG (tidak dapat membuka data apa pun).
 */
class UpdateKepemilikanSppgRequest extends FormRequest
{
    public const MAKS_SPPG = 100;

    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User && $this->user()?->can('kelolaKepemilikan', $target) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'sppg_ids' => ['present', 'array', 'max:'.self::MAKS_SPPG],
            'sppg_ids.*' => ['integer', 'distinct', Rule::exists('sppgs', 'id')->whereNull('deleted_at')],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $target = $this->route('user');

            if ($target instanceof User && ! $target->isInvestor()) {
                $validator->errors()->add('user', 'Kepemilikan SPPG hanya untuk pengguna berperan investor.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'sppg_ids.present' => 'Daftar SPPG (sppg_ids) wajib dikirim; kirim [] untuk mengosongkan.',
            'sppg_ids.max' => 'Maksimal '.self::MAKS_SPPG.' SPPG per investor.',
            'sppg_ids.*.exists' => 'SPPG tidak ditemukan.',
            'sppg_ids.*.distinct' => 'SPPG tidak boleh ganda.',
        ];
    }
}
