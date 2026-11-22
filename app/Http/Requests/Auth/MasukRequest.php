<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /auth/masuk (token pra-login): username ATAU no telepon + password. Kekuatan password
 * tidak diperiksa di sini (hanya saat diatur), agar pesan galat tidak membocorkan apa pun.
 */
class MasukRequest extends FormRequest
{
    use AturanKredensial;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalisasiKredensial();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'identitas' => $this->aturanIdentitas(),
            'password' => ['required', 'string', 'max:'.self::PASSWORD_MAX],
            'device_id' => $this->aturanDeviceId(),
            'label_perangkat' => $this->aturanLabelPerangkat(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->pesanKredensial();
    }
}
