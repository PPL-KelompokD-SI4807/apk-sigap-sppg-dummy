<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Nomor telepon/HP Indonesia sederhana: boleh diawali "+" atau "("; maksimal 30 karakter (sesuai kolom), berisi angka, spasi, "-" dan "()";
 * jumlah digit 8-15. Tidak memeriksa operator.
 */
class NomorTelepon implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)
            || strlen($value) > 30
            || preg_match('/^(\+|\()?[0-9][0-9\s\-()]{5,28}$/', $value) !== 1
            || ! in_array(strlen(preg_replace('/\D/', '', $value)), range(8, 15), true)) {
            $fail('Kolom :attribute harus berupa nomor telepon yang valid (8-15 digit).');
        }
    }
}
