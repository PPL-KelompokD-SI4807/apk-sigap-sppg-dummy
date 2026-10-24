<?php

namespace App\Http\Requests\BuktiDistribusi;

use App\Models\BuktiDistribusi;
use App\Models\JadwalDistribusi;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /bukti-distribusi (multipart). SPPG selalu dari jadwal distribusi (bukan body).
 * Status jadwal / gerbang mutu / penugasan relawan diperiksa ulang BuktiDistribusiService di
 * dalam transaksi (lockForUpdate).
 */
class StoreBuktiDistribusiRequest extends FormRequest
{
    use BuktiDistribusiRules;

    public function authorize(): bool
    {
        return $this->user()?->can('create', BuktiDistribusi::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $sppgId = $this->user()?->sppg_id;

        return [
            'jadwal_distribusi_id' => ['required', 'integer',
                Rule::exists('jadwal_distribusis', 'id')->where(fn ($q) => $q->where('sppg_id', $sppgId)->whereNull('deleted_at'))],
        ] + $this->aturanIsi(true);
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $jadwal = JadwalDistribusi::query()->find((int) $this->input('jadwal_distribusi_id'));
            $this->periksaPorsi($validator, $jadwal?->jumlah_porsi, $this->input('porsi_diterima'), $this->input('catatan_selisih'));
        }];
    }
}
