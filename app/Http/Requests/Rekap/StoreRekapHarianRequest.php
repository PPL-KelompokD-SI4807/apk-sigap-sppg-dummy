<?php

namespace App\Http\Requests\Rekap;

use App\Models\RekapHarian;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class StoreRekapHarianRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', RekapHarian::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $hariIni = Carbon::now(config('operasional.timezone'))->toDateString();

        return [
            'tanggal' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$hariIni],
            'catatan' => ['nullable', 'string', 'max:1000'],
            'kirim' => ['nullable', 'boolean'],
            'ai_draft_id' => ['nullable', 'uuid', 'exists:ai_drafts,uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'tanggal.date_format' => 'Format tanggal harus YYYY-MM-DD.',
            'tanggal.before_or_equal' => 'Tanggal rekap tidak boleh di masa depan.',
        ];
    }
}
