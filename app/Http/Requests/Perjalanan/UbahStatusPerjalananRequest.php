<?php

namespace App\Http\Requests\Perjalanan;

use App\Enums\StatusPerjalanan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UbahStatusPerjalananRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('ubahStatus', $this->route('notifikasiPerjalanan')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // "berangkat" adalah status awal (lewat POST), bukan tujuan transisi.
            'status' => ['required', Rule::enum(StatusPerjalanan::class)->except([StatusPerjalanan::Berangkat])],
            'alasan' => ['nullable', 'string', 'max:500', Rule::requiredIf(fn () => $this->input('status') === StatusPerjalanan::Dibatalkan->value)],
            'ai_draft_id' => ['nullable', 'uuid', 'exists:ai_drafts,uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'status.required' => 'Status tujuan wajib diisi.',
            'alasan.required' => 'Alasan pembatalan wajib diisi.',
        ];
    }
}
