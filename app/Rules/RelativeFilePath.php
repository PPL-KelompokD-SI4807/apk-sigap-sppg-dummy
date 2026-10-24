<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Path berkas relatif pada disk privat (kolom *_path). Menolak path absolut, traversal (..),
 * skema URL, dan karakter di luar [A-Za-z0-9._/-]. Unggah berkas ditangani modul unggahan.
 */
class RelativeFilePath implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)
            || strlen($value) > 255
            || preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $value) !== 1
            || str_contains($value, '..')
            || str_contains($value, '//')) {
            $fail(':attribute harus berupa path relatif berkas yang valid.');
        }
    }
}
