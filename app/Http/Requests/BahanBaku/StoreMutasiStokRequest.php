<?php

namespace App\Http\Requests\BahanBaku;

use App\Enums\JenisMutasi;
use App\Models\BahanBaku;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Aturan mutasi stok (masuk/keluar/penyesuaian); saldo dijaga StockService. */
class StoreMutasiStokRequest extends FormRequest
{
    public function authorize(): bool
    {
        $bahan = $this->route('bahan_baku');

        return $bahan instanceof BahanBaku && $this->user()->can('update', $bahan);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $bahan = $this->route('bahan_baku');
        $sppgId = $bahan instanceof BahanBaku ? $bahan->sppg_id : null;
        $jenis = $this->input('jenis');

        return [
            'jenis' => ['required', Rule::enum(JenisMutasi::class)],
            'jumlah' => [
                'required', 'numeric', 'decimal:0,3', 'between:-999999999,999999999', 'not_in:0',
                $jenis === JenisMutasi::Penyesuaian->value ? 'filled' : 'gt:0',
            ],
            'tanggal_mutasi' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'tanggal_kedaluwarsa' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:tanggal_mutasi'],
            'harga_satuan' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'pemasok_id' => ['nullable', 'integer', Rule::exists('pemasoks', 'id')->where('sppg_id', $sppgId)->whereNull('deleted_at')],
            'jadwal_produksi_id' => ['nullable', 'integer', Rule::exists('jadwal_produksis', 'id')->where('sppg_id', $sppgId)->whereNull('deleted_at')],
            'catatan' => [
                Rule::requiredIf(fn () => $jenis === JenisMutasi::Penyesuaian->value
                    || ($jenis === JenisMutasi::Keluar->value && ! $this->filled('jadwal_produksi_id'))),
                'nullable', 'string', 'max:500',
            ],
            'ai_draft_id' => ['nullable', 'uuid', 'exists:ai_drafts,uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'catatan.required' => 'Alasan (catatan) wajib diisi untuk penyesuaian, atau untuk mutasi keluar tanpa jadwal produksi.',
            'jumlah.gt' => 'Jumlah harus lebih besar dari 0 (penyesuaian boleh bertanda negatif).',
            'jumlah.not_in' => 'Jumlah tidak boleh 0.',
        ];
    }
}
