<?php

namespace App\Http\Requests\Auth\Web;

use Illuminate\Foundation\Http\FormRequest;

/** POST /web/auth/kode/kirim-ulang (R04-2): kirim ulang kode untuk tantangan_id dari langkah masuk. */
class KirimUlangKodeWebRequest extends FormRequest
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
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['tantangan_id.ulid' => 'tantangan_id tidak valid.'];
    }
}
