<?php

namespace App\Http\Requests\Mutu;

use App\Enums\HasilMutu;
use App\Http\Requests\Lampiran\AturanLampiranIds;
use App\Models\PemeriksaanMutu;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Aturan pemeriksaan mutu (satu sumber; store, update/koreksi, dan dry-run draf Agen AI).
 *
 * Checklist organoleptik = tiga butir `rasa_baik`, `aroma_baik`, `tampilan_baik` (boolean). Klien lama
 * boleh mengirim `organoleptik_baik` saja (berlaku untuk ketiga butir). `organoleptik_baik` disimpan
 * sebagai nilai turunan (true bila ketiganya true) dan nilai kirimannya diabaikan bila ketiga butir
 * dikirim. Foto lewat `lampiran_ids`; `foto_path` dari body tidak diterima.
 */
class StorePemeriksaanMutuRequest extends FormRequest
{
    protected function pemeriksaan(): ?PemeriksaanMutu
    {
        $p = $this->route('pemeriksaan_mutu');

        return $p instanceof PemeriksaanMutu ? $p : null;
    }

    /** Policy dicek sebelum validasi agar peran tanpa hak mendapat 403 (bukan 422). */
    public function authorize(): bool
    {
        $p = $this->pemeriksaan();

        return $p !== null ? $this->user()->can('update', $p) : $this->user()->can('create', PemeriksaanMutu::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $p = $this->pemeriksaan();
        $wajib = $p === null ? 'required' : 'sometimes';
        $sppgId = $p?->sppg_id ?? $this->user()->sppg_id;

        return ($p === null ? ['jadwal_produksi_id' => ['required', 'integer', Rule::exists('jadwal_produksis', 'id')->where('sppg_id', $sppgId)->whereNull('deleted_at')]] : []) + [
            'waktu_pemeriksaan' => ['sometimes', 'date', 'before_or_equal:'.now()->addMinutes(5)->toDateTimeString()],
            'suhu_makanan_c' => ['nullable', 'numeric', 'between:-50,200'],
            'kebersihan_dapur' => [$wajib, 'boolean'],
            'kebersihan_petugas' => [$wajib, 'boolean'],
            'rasa_baik' => $p === null ? ['required_without:organoleptik_baik', 'boolean'] : ['sometimes', 'boolean'],
            'aroma_baik' => $p === null ? ['required_without:organoleptik_baik', 'boolean'] : ['sometimes', 'boolean'],
            'tampilan_baik' => $p === null ? ['required_without:organoleptik_baik', 'boolean'] : ['sometimes', 'boolean'],
            'organoleptik_baik' => $p === null ? ['required_without_all:rasa_baik,aroma_baik,tampilan_baik', 'boolean'] : ['sometimes', 'boolean'],
            'catatan_organoleptik' => ['nullable', 'string', 'max:2000'],
            'sampel_disimpan' => ['sometimes', 'boolean'],
            'hasil' => [$wajib, Rule::enum(HasilMutu::class)],
            'tindakan_korektif' => ['nullable', 'string', 'max:2000'],
            'catatan_koreksi' => ['nullable', 'string', 'max:2000'],
            'ai_draft_id' => ['nullable', 'uuid', 'exists:ai_drafts,uuid'],
        ] + AturanLampiranIds::untuk('mutu');
    }
}
