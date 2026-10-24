<?php

namespace App\Http\Requests\LogProduksi;

use App\Models\LogProduksi;
use App\Rules\RelativeFilePath;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Aturan log produksi (satu sumber; store, update, dan dry-run draf Agen AI). */
class StoreLogProduksiRequest extends FormRequest
{
    protected function log(): ?LogProduksi
    {
        $log = $this->route('log_produksi');

        return $log instanceof LogProduksi ? $log : null;
    }

    /** Policy dicek sebelum validasi agar peran tanpa hak mendapat 403 (bukan 422). */
    public function authorize(): bool
    {
        $log = $this->log();

        return $log !== null ? $this->user()->can('update', $log) : $this->user()->can('create', LogProduksi::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $log = $this->log();
        $wajib = $log === null ? 'required' : 'sometimes';
        $sppgId = $log?->sppg_id ?? $this->user()->sppg_id;

        return ($log === null ? ['jadwal_produksi_id' => ['required', 'integer', Rule::exists('jadwal_produksis', 'id')->where('sppg_id', $sppgId)->whereNull('deleted_at')]] : []) + [
            'realisasi_porsi' => [$wajib, 'integer', 'min:0', 'max:1000000'],
            'sisa_makanan_kg' => ['sometimes', 'numeric', 'min:0', 'max:99999'],
            'waktu_mulai' => ['nullable', 'date_format:H:i'],
            'jam_selesai' => array_merge(['nullable', 'date_format:H:i'], $this->filled('waktu_mulai') ? ['after:waktu_mulai'] : []),
            'kendala' => ['nullable', 'string', 'max:2000'],
            'foto_path' => ['nullable', new RelativeFilePath],
            'ai_draft_id' => ['nullable', 'uuid', 'exists:ai_drafts,uuid'],
        ];
    }
}
