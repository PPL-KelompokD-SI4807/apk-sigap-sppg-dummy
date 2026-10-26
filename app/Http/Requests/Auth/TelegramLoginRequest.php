<?php

namespace App\Http\Requests\Auth;

use App\Services\Telegram\InitDataValidator;
use Illuminate\Foundation\Http\FormRequest;

class TelegramLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'init_data' => ['required', 'string', 'max:'.InitDataValidator::MAX_LENGTH],
            'device' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'init_data.required' => 'init_data wajib diisi (Telegram.WebApp.initData).',
            'device.regex' => 'device hanya boleh huruf, angka, titik, garis bawah, dan tanda hubung.',
        ];
    }
}
