<?php

namespace App\Http\Requests\Perjalanan;

use App\Models\NotifikasiPerjalanan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Aturan dipakai POST /notifikasi-perjalanan, POST /notifikasi-perjalanan/pratinjau, dan dry-run draf AI. */
class StoreNotifikasiPerjalananRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', NotifikasiPerjalanan::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $sppgId = $this->user()?->sppg_id;

        return [
            'jadwal_distribusi_id' => [
                'required', 'integer',
                Rule::exists('jadwal_distribusis', 'id')->where(
                    fn ($q) => $q->where('sppg_id', $sppgId)->whereNull('deleted_at')
                ),
            ],
            'waktu_berangkat' => ['nullable', 'date', 'before_or_equal:+30 minutes'],
            'estimasi_menit' => ['nullable', 'integer', 'min:1', 'max:'.config('operasional.perjalanan.durasi_maksimum_menit', 720)],
            'ai_draft_id' => ['nullable', 'uuid', 'exists:ai_drafts,uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'jadwal_distribusi_id.required' => 'Jadwal distribusi wajib dipilih.',
            'jadwal_distribusi_id.exists' => 'Jadwal distribusi tidak ditemukan.',
            'waktu_berangkat.before_or_equal' => 'Waktu berangkat tidak boleh lebih dari 30 menit ke depan.',
            'estimasi_menit.min' => 'Estimasi durasi minimal 1 menit.',
        ];
    }
}
