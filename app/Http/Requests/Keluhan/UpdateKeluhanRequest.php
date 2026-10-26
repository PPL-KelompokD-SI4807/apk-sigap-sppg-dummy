<?php

namespace App\Http\Requests\Keluhan;

use App\Enums\KategoriKeluhan;
use App\Enums\PrioritasKeluhan;
use App\Enums\StatusKeluhan;
use App\Enums\SumberKeluhan;
use App\Http\Requests\Lampiran\AturanLampiranIds;
use App\Models\FeedbackKeluhan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Admin boleh mengubah semua field; peran "RU" lain (kepala_sppg, ahli_gizi) hanya status dan
 * tindak_lanjut (field lain tidak lolos validasi sehingga diabaikan). `lampiran_ids` (foto keluhan) boleh
 * dikirim semua peran yang lolos otorisasi update.
 */
class UpdateKeluhanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('keluhan')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var FeedbackKeluhan $keluhan */
        $keluhan = $this->route('keluhan');

        $rules = [
            'status' => ['sometimes', 'required', Rule::enum(StatusKeluhan::class)],
            'tindak_lanjut' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'ai_draft_id' => ['nullable', 'uuid', 'exists:ai_drafts,uuid'],
        ] + AturanLampiranIds::untuk('keluhan');

        if ($this->user()?->isAdmin()) {
            $terkait = fn (string $tabel) => [
                'sometimes', 'nullable', 'integer',
                Rule::exists($tabel, 'id')->where(fn ($q) => $q->where('sppg_id', $keluhan->sppg_id)->whereNull('deleted_at')),
            ];

            $rules += [
                'sumber' => ['sometimes', 'required', Rule::enum(SumberKeluhan::class)],
                'kategori' => ['sometimes', 'required', Rule::enum(KategoriKeluhan::class)],
                'prioritas' => ['sometimes', 'nullable', Rule::enum(PrioritasKeluhan::class)],
                'titik_layanan_id' => $terkait('titik_layanans'),
                'jadwal_produksi_id' => $terkait('jadwal_produksis'),
                'deskripsi' => ['sometimes', 'required', 'string', 'max:2000'],
            ];
        }

        return $rules;
    }
}
