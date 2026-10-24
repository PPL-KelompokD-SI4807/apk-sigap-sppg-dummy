<?php

namespace App\Http\Requests\Keluhan;

use App\Enums\KategoriKeluhan;
use App\Enums\PrioritasKeluhan;
use App\Enums\SumberKeluhan;
use App\Models\FeedbackKeluhan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Aturan dipakai POST /keluhan dan dry-run draf Agen AI. sppg_id dari body hanya dipercaya untuk Admin. */
class StoreKeluhanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', FeedbackKeluhan::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $user = $this->user();
        $admin = $user?->isAdmin() === true;
        $sppgId = $admin ? $this->input('sppg_id') : $user?->sppg_id;

        $terkait = fn (string $tabel) => [
            'nullable', 'integer',
            Rule::exists($tabel, 'id')->where(fn ($q) => $q->where('sppg_id', $sppgId)->whereNull('deleted_at')),
        ];

        return [
            'sppg_id' => $admin
                ? ['required', 'integer', Rule::exists('sppgs', 'id')->whereNull('deleted_at')]
                : ['exclude'],
            'sumber' => ['required', Rule::enum(SumberKeluhan::class)],
            'kategori' => ['required', Rule::enum(KategoriKeluhan::class)],
            'prioritas' => ['nullable', Rule::enum(PrioritasKeluhan::class)],
            'titik_layanan_id' => $terkait('titik_layanans'),
            'jadwal_produksi_id' => $terkait('jadwal_produksis'),
            'batch_tidak_diketahui' => ['nullable', 'boolean'],
            'deskripsi' => ['required', 'string', 'max:2000'],
            'ai_draft_id' => ['nullable', 'uuid', 'exists:ai_drafts,uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'sppg_id.required' => 'SPPG wajib dipilih.',
            'sumber.required' => 'Sumber keluhan wajib dipilih.',
            'kategori.required' => 'Kategori keluhan wajib dipilih.',
            'deskripsi.required' => 'Deskripsi keluhan wajib diisi.',
            'titik_layanan_id.exists' => 'Titik layanan tidak ditemukan.',
            'jadwal_produksi_id.exists' => 'Jadwal produksi tidak ditemukan.',
        ];
    }
}
