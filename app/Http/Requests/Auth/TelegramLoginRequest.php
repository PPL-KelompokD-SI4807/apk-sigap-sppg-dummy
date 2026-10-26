<?php

namespace App\Http\Requests\Auth;

use App\Services\Telegram\InitDataValidator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Lapis 1 (setiap Mini App dibuka): initData Telegram + device_id opsional. Bila klien mengirim
 * token login penuh miliknya di header Authorization, server memeriksa apakah sesi itu masih aktif
 * untuk akun Telegram yang sama dan perangkat yang sama.
 */
class TelegramLoginRequest extends FormRequest
{
    use AturanKredensial;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'init_data' => ['required', 'string', 'max:'.InitDataValidator::MAX_LENGTH],
            'device_id' => ['sometimes', 'nullable', 'string', 'regex:'.self::DEVICE_ID_REGEX],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'init_data.required' => 'init_data wajib diisi (Telegram.WebApp.initData).',
            'device_id.regex' => 'device_id tidak valid.',
        ];
    }
}
