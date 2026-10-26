<?php

namespace App\Http\Requests\Jadwal;

use App\Enums\StatusJadwalProduksi;
use App\Models\JadwalProduksi;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Aturan jadwal produksi (satu sumber; store, update, dan dry-run draf Agen AI). */
class StoreJadwalProduksiRequest extends FormRequest
{
    protected function jadwal(): ?JadwalProduksi
    {
        $jadwal = $this->route('jadwal');

        return $jadwal instanceof JadwalProduksi ? $jadwal : null;
    }

    /** Policy dicek sebelum validasi agar peran tanpa hak mendapat 403 (bukan 422). */
    public function authorize(): bool
    {
        $jadwal = $this->jadwal();

        return $jadwal !== null ? $this->user()->can('update', $jadwal) : $this->user()->can('create', JadwalProduksi::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $jadwal = $this->jadwal();
        $sppgId = $jadwal?->sppg_id ?? $this->user()->sppg_id;
        $buat = $jadwal === null;
        $wajib = $buat ? 'required' : 'sometimes';

        return [
            'menu_id' => [$wajib, 'integer', Rule::exists('menus', 'id')->where('sppg_id', $sppgId)->whereNull('deleted_at')],
            'tanggal_produksi' => array_filter([$wajib, 'date_format:Y-m-d', $buat ? 'after_or_equal:today' : null]),
            'jam_mulai_masak' => ['nullable', 'date_format:H:i'],
            'target_porsi' => [$wajib, 'integer', 'min:1', 'max:1000000'],
            'status' => $buat ? ['sometimes', Rule::in([StatusJadwalProduksi::Direncanakan->value])] : ['sometimes', Rule::enum(StatusJadwalProduksi::class)],
            'catatan' => ['nullable', 'string', 'max:2000'],
            'ai_draft_id' => ['nullable', 'uuid', 'exists:ai_drafts,uuid'],
        ];
    }
}
