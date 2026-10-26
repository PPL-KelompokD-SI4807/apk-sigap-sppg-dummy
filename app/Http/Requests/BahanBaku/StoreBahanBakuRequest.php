<?php

namespace App\Http\Requests\BahanBaku;

use App\Enums\KategoriBahan;
use App\Models\BahanBaku;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Aturan bahan baku (satu sumber): dipakai store, update, dan dry-run draf Agen AI.
 * Stok TIDAK dapat diisi di sini (hanya lewat mutasi stok).
 */
class StoreBahanBakuRequest extends FormRequest
{
    /** Policy dicek sebelum validasi agar peran tanpa hak mendapat 403 (bukan 422). */
    public function authorize(): bool
    {
        $bahan = $this->route('bahan_baku');

        return $bahan instanceof BahanBaku
            ? $this->user()->can('update', $bahan)
            : $this->user()->can('create', BahanBaku::class);
    }

    /** SPPG tujuan: Admin dari body, selain Admin dari akun. */
    protected function sppgId(): ?int
    {
        $user = $this->user();
        $id = $user->isAdmin() ? $this->input('sppg_id') : $user->sppg_id;

        return is_numeric($id) ? (int) $id : null;
    }

    protected function ignoreId(): ?int
    {
        return null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $sppgId = $this->sppgId();
        $wajib = $this->isMethod('POST') ? 'required' : 'sometimes';

        $aturanSppg = $this->user()->isAdmin() && $this->isMethod('POST')
            ? ['sppg_id' => ['required', 'integer', Rule::exists('sppgs', 'id')->whereNull('deleted_at')]]
            : [];

        return $aturanSppg + [
            'pemasok_id' => ['nullable', 'integer', Rule::exists('pemasoks', 'id')->where('sppg_id', $sppgId)->whereNull('deleted_at')],
            'kode' => ['nullable', 'string', 'max:30', Rule::unique('bahan_bakus', 'kode')->where('sppg_id', $sppgId)->whereNull('deleted_at')->ignore($this->ignoreId())],
            'nama' => [$wajib, 'string', 'max:255'],
            'kategori' => [$wajib, Rule::enum(KategoriBahan::class)],
            'satuan' => [$wajib, 'string', 'max:20'],
            'stok_minimum' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'kedaluwarsa_terdekat' => ['nullable', 'date_format:Y-m-d'],
            'ai_draft_id' => ['nullable', 'uuid', 'exists:ai_drafts,uuid'],
        ];
    }
}
