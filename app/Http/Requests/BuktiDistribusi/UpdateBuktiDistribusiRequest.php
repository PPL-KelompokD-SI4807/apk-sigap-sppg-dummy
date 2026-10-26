<?php

namespace App\Http\Requests\BuktiDistribusi;

use App\Models\BuktiDistribusi;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * PUT/PATCH /bukti-distribusi/{id}. Untuk mengganti foto kirim multipart POST dengan _method=PUT.
 * Jadwal distribusi tidak dapat dipindah (hapus lalu catat ulang).
 */
class UpdateBuktiDistribusiRequest extends FormRequest
{
    use BuktiDistribusiRules;

    public function authorize(): bool
    {
        $bukti = $this->route('buktiDistribusi');

        return $bukti instanceof BuktiDistribusi && ($this->user()?->can('update', $bukti) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['jadwal_distribusi_id' => ['prohibited']] + $this->aturanIsi(false);
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var BuktiDistribusi $bukti */
            $bukti = $this->route('buktiDistribusi');
            $jadwal = $bukti->jadwalDistribusi()->withTrashed()->first();

            $this->periksaPorsi(
                $validator,
                $jadwal?->jumlah_porsi,
                $this->has('porsi_diterima') ? $this->input('porsi_diterima') : $bukti->porsi_diterima,
                $this->has('catatan_selisih') ? $this->input('catatan_selisih') : $bukti->catatan_selisih,
            );
        }];
    }
}
