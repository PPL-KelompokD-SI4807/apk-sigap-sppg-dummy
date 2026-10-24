<?php

namespace App\Http\Requests\Jadwal;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Enums\StatusJadwalDistribusi;
use App\Models\JadwalDistribusi;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Aturan jadwal distribusi (satu sumber; store, update, dan dry-run draf Agen AI). */
class StoreJadwalDistribusiRequest extends FormRequest
{
    protected function jadwal(): ?JadwalDistribusi
    {
        $jadwal = $this->route('jadwal_distribusi');

        return $jadwal instanceof JadwalDistribusi ? $jadwal : null;
    }

    /** Policy dicek sebelum validasi agar peran tanpa hak mendapat 403 (bukan 422). */
    public function authorize(): bool
    {
        $jadwal = $this->jadwal();

        return $jadwal !== null ? $this->user()->can('update', $jadwal) : $this->user()->can('create', JadwalDistribusi::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $jadwal = $this->jadwal();
        $sppgId = $jadwal?->sppg_id ?? $this->user()->sppg_id;
        $buat = $jadwal === null;
        $wajib = $buat ? 'required' : 'sometimes';
        $ada = fn (string $tabel) => Rule::exists($tabel, 'id')->where('sppg_id', $sppgId)->whereNull('deleted_at');

        return ($buat ? ['jadwal_produksi_id' => ['required', 'integer', $ada('jadwal_produksis')]] : []) + [
            'titik_layanan_id' => [$wajib, 'integer', $ada('titik_layanans')],
            'armada_id' => ['nullable', 'integer', $ada('armadas')],
            'pengemudi_id' => ['nullable', 'integer', $ada('pengemudis')],
            // Petugas = relawan unit distribusi yang aktif di SPPG yang sama (hak baca bukti mengikuti kolom ini).
            'petugas_id' => ['nullable', 'integer', Rule::exists('users', 'id')
                ->where('sppg_id', $sppgId)
                ->where('role', Role::Relawan->value)
                ->where('unit', RelawanUnit::Distribusi->value)
                ->where('is_active', true)
                ->whereNull('deleted_at')],
            'tanggal_distribusi' => ['nullable', 'date_format:Y-m-d'],
            'jam_berangkat' => ['nullable', 'date_format:H:i'],
            'jam_rencana_tiba' => ['nullable', 'date_format:H:i'],
            'jumlah_porsi' => [$wajib, 'integer', 'min:1', 'max:1000000'],
            'status' => $buat ? ['sometimes', Rule::in([StatusJadwalDistribusi::Menunggu->value])] : ['sometimes', Rule::enum(StatusJadwalDistribusi::class)],
            'catatan' => ['nullable', 'string', 'max:2000'],
            'ai_draft_id' => ['nullable', 'uuid', 'exists:ai_drafts,uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'petugas_id.exists' => 'Petugas harus relawan unit distribusi yang aktif di SPPG ini.',
        ];
    }
}
