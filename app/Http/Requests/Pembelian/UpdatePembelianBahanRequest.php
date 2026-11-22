<?php

namespace App\Http\Requests\Pembelian;

use App\Models\PembelianBahan;
use Illuminate\Foundation\Http\FormRequest;

/** PATCH /pembelian-bahan/{id}. Wajib `versi` (kunci optimistik -> 409 bila basi). */
class UpdatePembelianBahanRequest extends FormRequest
{
    use AturanPembelianBahan;

    public function authorize(): bool
    {
        $model = $this->route('pembelian_bahan');

        return $model instanceof PembelianBahan && ($this->user()?->can('update', $model) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $model = $this->route('pembelian_bahan');
        $sppgId = $model instanceof PembelianBahan ? (int) $model->sppg_id : $this->user()?->sppg_id;

        return ['versi' => ['required', 'integer', 'min:1']] + $this->aturanPembelian(false, $sppgId);
    }
}
