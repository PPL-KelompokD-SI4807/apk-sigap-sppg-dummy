<?php

namespace App\Http\Requests\Keuangan;

use App\Models\PencairanDana;
use Illuminate\Foundation\Http\FormRequest;

/** PUT/PATCH /keuangan/pencairan/{id}. Ganti berkas: multipart POST dengan _method=PUT. */
class UpdatePencairanRequest extends FormRequest
{
    use AturanKeuangan;

    public function authorize(): bool
    {
        $model = $this->route('pencairanDana');

        return $model instanceof PencairanDana && ($this->user()?->can('update', $model) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->aturanPencairan(false);
    }
}
