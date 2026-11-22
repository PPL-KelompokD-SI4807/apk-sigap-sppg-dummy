<?php

namespace App\Http\Requests\Pembelian;

use App\Models\PembelianBahan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Verifikasi akuntan. Alasan wajib (5..500) saat ditolak. Tidak menerima total/harga/status.
 * Policy `verifikasi` dicek sebelum validasi (peran lain/pencatat sendiri -> 403).
 */
class VerifikasiPembelianBahanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $model = $this->route('pembelian_bahan');

        return $model instanceof PembelianBahan && $this->user()->can('verifikasi', $model);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'keputusan' => ['required', 'string', Rule::in(['disetujui', 'ditolak'])],
            'alasan' => [Rule::requiredIf($this->input('keputusan') === 'ditolak'), 'nullable', 'string', 'min:5', 'max:500'],
            'versi' => ['required', 'integer', 'min:1'],
        ];
    }
}
