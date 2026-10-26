<?php

namespace App\Rules;

use App\Support\Telepon;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * No telepon (identitas login R-03) harus dikenali (format 08xx/62xx/+62xx) dan belum dipakai akun
 * lain, dibandingkan dalam bentuk normal E.164 (termasuk akun terhapus lunak, sesuai indeks unik).
 */
class TeleponUnik implements ValidationRule
{
    public function __construct(private readonly ?int $abaikanUserId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $normal = Telepon::normalisasi($value);

        if ($normal === null) {
            $fail('No telepon tidak dikenali. Gunakan format 08xx atau +62xx.');

            return;
        }

        $dipakai = DB::table('users')->where('phone', $normal)
            ->when($this->abaikanUserId !== null, fn ($q) => $q->where('id', '!=', $this->abaikanUserId))
            ->exists();

        if ($dipakai) {
            $fail('No telepon sudah dipakai akun lain.');
        }
    }
}
