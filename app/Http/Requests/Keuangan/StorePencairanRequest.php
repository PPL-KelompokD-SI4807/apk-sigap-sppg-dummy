<?php

namespace App\Http\Requests\Keuangan;

use App\Models\PencairanDana;
use Illuminate\Foundation\Http\FormRequest;

/** POST /keuangan/pencairan (JSON atau multipart). SPPG = SPPG pengguna (hanya akuntan; admin R). */
class StorePencairanRequest extends FormRequest
{
    use AturanKeuangan;

    public function authorize(): bool
    {
        return ($this->user()?->can('create', PencairanDana::class) ?? false) && $this->user()?->sppg_id !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->aturanPencairan(true);
    }
}
