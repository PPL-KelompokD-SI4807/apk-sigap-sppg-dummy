<?php

namespace App\Http\Requests\Pengguna;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /pengguna/{user}/kepemilikan-sppg (R-06a): tautkan satu SPPG ke investor (idempoten).
 * Hanya Admin (UserPolicy::kelolaKepemilikan); target wajib berperan investor.
 */
class TautkanKepemilikanSppgRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User && $this->user()?->can('kelolaKepemilikan', $target) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'sppg_id' => ['required', 'integer', Rule::exists('sppgs', 'id')->whereNull('deleted_at')],
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
        return ['sppg_id.exists' => 'SPPG tidak ditemukan.'];
    }
}
