<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/** POST /auth/token/kirim-ulang (token pra-login): kirim ulang token 6 digit (kode lama hangus). */
class KirimUlangTokenRequest extends FormRequest
{
    use AturanKredensial;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tantangan_id' => ['required', 'string', 'ulid'],
            'device_id' => $this->aturanDeviceId(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->pesanKredensial() + ['tantangan_id.ulid' => 'tantangan_id tidak valid.'];
    }
}
