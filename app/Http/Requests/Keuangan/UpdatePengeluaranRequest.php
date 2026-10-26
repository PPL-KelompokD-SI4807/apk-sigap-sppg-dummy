<?php

namespace App\Http\Requests\Keuangan;

use App\Models\Pengeluaran;
use Illuminate\Foundation\Http\FormRequest;

/** PUT/PATCH /keuangan/pengeluaran/{id}. Ganti berkas: multipart POST dengan _method=PUT. */
class UpdatePengeluaranRequest extends FormRequest
{
    use AturanKeuangan;

    public function authorize(): bool
    {
        $model = $this->route('pengeluaran');

        return $model instanceof Pengeluaran && ($this->user()?->can('update', $model) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->aturanPengeluaran(false);
    }
}
