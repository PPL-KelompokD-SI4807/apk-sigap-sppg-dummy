<?php

namespace App\Http\Requests\Rekap;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRekapHarianRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('rekapHarian')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'catatan' => ['present', 'nullable', 'string', 'max:1000'],
            'ai_draft_id' => ['nullable', 'uuid', 'exists:ai_drafts,uuid'],
        ];
    }
}
