<?php

namespace App\Http\Requests\Pemasok;

use App\Enums\JenisPemasok;
use App\Http\Requests\Concerns\HasAiDraftId;
use App\Http\Requests\Concerns\ResolvesTargetSppg;
use App\Rules\NomorTelepon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class PemasokRequest extends FormRequest
{
    use HasAiDraftId, ResolvesTargetSppg;

    abstract protected function creating(): bool;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $req = $this->creating() ? 'required' : 'sometimes';

        $rules = [
            'nama' => [$req, 'string', 'max:255'],
            'jenis' => [$req, Rule::enum(JenisPemasok::class)],
            'kontak' => ['nullable', new NomorTelepon],
            'alamat' => ['nullable', 'string', 'max:255'],
            'jenis_bahan' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];

        if ($this->creating()) {
            $rules['sppg_id'] = $this->sppgRule();
        }

        return $rules + $this->aiDraftRules();
    }
}
