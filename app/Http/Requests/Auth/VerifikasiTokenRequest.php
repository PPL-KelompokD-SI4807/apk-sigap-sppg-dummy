<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/** POST /auth/token/verifikasi (token pra-login): konfirmasi token 6 digit dari bot. */
class VerifikasiTokenRequest extends FormRequest
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
            'kode' => ['required', 'string', 'regex:/^\d{'.(int) config('kredensial.token.digit', 6).'}$/'],
            'device_id' => $this->aturanDeviceId(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->pesanKredensial() + [
            'tantangan_id.ulid' => 'tantangan_id tidak valid.',
            'kode.regex' => 'Token harus '.(int) config('kredensial.token.digit', 6).' digit angka.',
        ];
    }
}
