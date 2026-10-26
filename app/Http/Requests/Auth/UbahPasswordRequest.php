<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /auth/password (login penuh): password lama + password baru (+ konfirmasi). Sesi perangkat
 * ini dipertahankan; semua sesi & perangkat tepercaya lain dicabut.
 */
class UbahPasswordRequest extends FormRequest
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
            'password_lama' => ['required', 'string', 'max:'.self::PASSWORD_MAX],
            'password' => [...$this->aturanPassword($user->username), 'different:password_lama'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->pesanKredensial() + [
            'password_lama.required' => 'Isi password lama.',
            'password.different' => 'Password baru harus berbeda dari password lama.',
        ];
    }
}
