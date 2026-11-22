<?php

namespace App\Http\Requests\Auth\Web;

use App\Http\Requests\Auth\AturanKredensial;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /web/auth/masuk (R04-2): identitas (username ATAU no telepon) + password. Hanya format yang divalidasi;
 * galat validasi tidak pernah bergantung pada keberadaan akun (anti-enumerasi). Label browser diturunkan server
 * dari User-Agent, bukan dari isian.
 */
class MasukWebRequest extends FormRequest
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
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->pesanKredensial();
    }
}
