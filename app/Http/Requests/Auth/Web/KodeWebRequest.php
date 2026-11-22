<?php

namespace App\Http\Requests\Auth\Web;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /web/auth/kode (R04-2): tantangan_id dari langkah masuk + kode 6 digit dari bot. `ingat_browser` (R04-3,
 * bawaan false): jadikan browser ini browser tepercaya 30 hari (diabaikan tanpa galat untuk Admin).
 */
class KodeWebRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tantangan_id' => ['required', 'string', 'ulid'],
            'kode' => ['required', 'string', 'regex:/^\d{'.(int) config('kredensial.token.digit', 6).'}$/'],
            'ingat_browser' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'tantangan_id.ulid' => 'tantangan_id tidak valid.',
            'kode.regex' => 'Kode harus '.(int) config('kredensial.token.digit', 6).' digit angka.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['kode' => 'kode'];
    }
}
