<?php

namespace App\Http\Requests\TitikLayanan;

use App\Enums\JenisTitikLayanan;
use App\Http\Requests\Concerns\HasAiDraftId;
use App\Http\Requests\Concerns\ResolvesTargetSppg;
use App\Rules\NomorTelepon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Aturan validasi titik layanan. `pic_telegram_chat_id` tidak dapat diisi dari body
 * (hanya lewat undangan PIC); `sppg_id` hanya saat membuat (Admin).
 */
abstract class TitikLayananRequest extends FormRequest
{
    use HasAiDraftId, ResolvesTargetSppg;

    abstract protected function creating(): bool;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $req = $this->creating() ? 'required' : 'sometimes';

        $rules = [
            'nama' => [$req, 'string', 'max:255'],
            'jenis' => [$req, Rule::enum(JenisTitikLayanan::class)],
            'alamat' => [$req, 'string', 'max:255'],
            'jarak_km' => ['sometimes', 'numeric', 'min:0', 'max:9999.99'],
            'jumlah_sasaran' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'pic_nama' => [$req, 'string', 'max:255'],
            'pic_telepon' => ['nullable', new NomorTelepon],
            'is_active' => ['sometimes', 'boolean'],
        ];

        if ($this->creating()) {
            $rules['sppg_id'] = $this->sppgRule();
        }

        return $rules + $this->aiDraftRules();
    }
}
