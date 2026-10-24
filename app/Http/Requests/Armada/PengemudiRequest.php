<?php

namespace App\Http\Requests\Armada;

use App\Http\Requests\Concerns\HasAiDraftId;
use App\Http\Requests\Concerns\ResolvesTargetSppg;
use App\Models\Pengemudi;
use App\Rules\NomorTelepon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Aturan validasi pengemudi. Bila no_sim ada maka sim_berlaku_hingga wajib; tanggal masa berlaku
 * yang baru diisi/diubah tidak boleh sebelum hari ini (nilai lama yang tidak diubah tidak diperiksa
 * agar pengubahan data lain tidak terblokir).
 */
abstract class PengemudiRequest extends FormRequest
{
    use HasAiDraftId, ResolvesTargetSppg;

    abstract protected function creating(): bool;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $req = $this->creating() ? 'required' : 'sometimes';

        $rules = [
            'nama' => [$req, 'string', 'max:255'],
            'no_hp' => ['nullable', new NomorTelepon],
            'no_sim' => ['nullable', 'string', 'regex:/^[A-Za-z0-9 \-]{8,20}$/'],
            'sim_berlaku_hingga' => ['nullable', 'date_format:Y-m-d'],
            'is_active' => ['sometimes', 'boolean'],
        ];

        if ($this->creating()) {
            $rules['sppg_id'] = $this->sppgRule();
        }

        return $rules + $this->aiDraftRules();
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $current = $this->route('pengemudi');
            $current = $current instanceof Pengemudi ? $current : null;

            $noSim = $this->has('no_sim') ? $this->input('no_sim') : $current?->no_sim;
            $berlaku = $this->has('sim_berlaku_hingga') ? $this->input('sim_berlaku_hingga') : $current?->sim_berlaku_hingga?->toDateString();

            if ($noSim !== null && $noSim !== '' && $berlaku === null) {
                $validator->errors()->add('sim_berlaku_hingga', 'Masa berlaku SIM wajib diisi bila nomor SIM dicatat.');
            }

            $changed = $this->filled('sim_berlaku_hingga')
                && $this->input('sim_berlaku_hingga') !== $current?->sim_berlaku_hingga?->toDateString();

            if ($changed && $this->input('sim_berlaku_hingga') < today()->toDateString()) {
                $validator->errors()->add('sim_berlaku_hingga', 'SIM sudah kedaluwarsa; masa berlaku tidak boleh sebelum hari ini.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'no_sim.regex' => 'Nomor SIM hanya boleh huruf, angka, spasi, dan tanda hubung (8-20 karakter).',
            'sim_berlaku_hingga.date_format' => 'Masa berlaku SIM harus berformat YYYY-MM-DD.',
        ];
    }
}
