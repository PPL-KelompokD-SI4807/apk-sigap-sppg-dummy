<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /auth/aktivasi (token pra-login): akun yang belum punya password (undangan baru,
 * sigap:buat-admin, atau password di-reset Admin) mengatur username, no telepon (opsional), dan
 * password. Selesai setelah token 6 digit dari bot dikonfirmasi (POST /auth/token/verifikasi).
 * username wajib bila akun belum punya username.
 */
class AktivasiRequest extends FormRequest
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
        $username = is_string($this->input('username')) ? $this->input('username') : $user->username;

        return [
            'username' => $this->aturanUsername($user->username === null),
            'phone' => $this->aturanTelepon(),
            'password' => $this->aturanPassword($username),
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
