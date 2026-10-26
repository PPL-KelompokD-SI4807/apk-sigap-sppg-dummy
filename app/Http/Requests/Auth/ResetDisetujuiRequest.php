<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /auth/lupa-password/lanjut (token pra-login): password baru setelah permintaan reset
 * DISETUJUI Admin. Berlaku setelah token 6 digit dari bot dikonfirmasi (POST /auth/token/verifikasi).
 */
class ResetDisetujuiRequest extends FormRequest
{
    use AturanKredensial;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();

        return [
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
