<?php

namespace App\Http\Requests\Pembelian;

use App\Models\PembelianBahan;
use Illuminate\Foundation\Http\FormRequest;

/** POST /pembelian-bahan. Hanya staf gudang (Policy create) dengan SPPG sendiri; Policy dicek sebelum validasi (403). */
class StorePembelianBahanRequest extends FormRequest
{
    use AturanPembelianBahan;

    public function authorize(): bool
    {
        return ($this->user()?->can('create', PembelianBahan::class) ?? false) && $this->user()?->sppg_id !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->aturanPembelian(true, $this->user()?->sppg_id);
    }
}
