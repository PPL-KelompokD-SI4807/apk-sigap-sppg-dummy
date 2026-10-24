<?php

namespace App\Http\Requests\Keuangan;

use App\Models\Pengeluaran;
use Illuminate\Foundation\Http\FormRequest;

/** POST /keuangan/pengeluaran (JSON atau multipart). SPPG = SPPG pengguna (hanya akuntan; admin R). */
class StorePengeluaranRequest extends FormRequest
{
    use AturanKeuangan;

    public function authorize(): bool
    {
        return ($this->user()?->can('create', Pengeluaran::class) ?? false) && $this->user()?->sppg_id !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->aturanPengeluaran(true);
    }
}
