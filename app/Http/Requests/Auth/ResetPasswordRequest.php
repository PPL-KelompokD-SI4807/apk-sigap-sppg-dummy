<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /auth/reset-password (token pra-login): lupa password. Identitas harus milik akun Telegram
 * yang sama; password baru berlaku setelah token 6 digit dari bot dikonfirmasi.
 */
class ResetPasswordRequest extends FormRequest
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
        /** @var User $user */
        $user = $this->user();

        return [
            'identitas' => $this->aturanIdentitas(),
            'password' => $this->aturanPassword($user->username),
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
