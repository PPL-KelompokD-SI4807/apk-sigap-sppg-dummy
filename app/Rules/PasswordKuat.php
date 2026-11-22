<?php

namespace App\Rules;

use App\Support\SandiUmum;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Bagian aturan password R-03 yang tidak ada di Illuminate\Validation\Rules\Password:
 * tidak sama dengan username (tanpa membedakan huruf, juga setelah angka/simbol dibuang) dan
 * tidak termasuk daftar sandi umum lokal. Panjang/huruf besar-kecil/angka dari Password::min(8).
 */
class PasswordKuat implements ValidationRule
{
    public function __construct(private readonly ?string $username) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $kecil = mb_strtolower($value);
        $username = $this->username === null ? '' : mb_strtolower(trim($this->username));

        if ($username !== '' && ($kecil === $username || (string) preg_replace('/[^\p{L}\p{N}]/u', '', $kecil) === (string) preg_replace('/[^\p{L}\p{N}]/u', '', $username))) {
            $fail('Password tidak boleh sama dengan username.');

            return;
        }

        if (SandiUmum::termasuk($value)) {
            $fail('Password terlalu umum dan mudah ditebak. Pilih password lain.');
        }
    }
}
