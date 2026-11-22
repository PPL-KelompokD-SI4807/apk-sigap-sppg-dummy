<?php

namespace App\Http\Requests\Sppg;

use App\Models\Sppg;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT/PATCH /sppg/{sppg}/saldo-awal. Hanya Akuntan (SPPG-nya) dan Admin (SppgPolicy::ubahSaldoAwal); peran lain 403
 * sebelum validasi. Nominal rupiah bulat >= 0 (nol sah: SPPG tanpa saldo).
 */
class SaldoAwalRequest extends FormRequest
{
    public const NOMINAL_MAKS = 9_999_999_999_999;

    public function authorize(): bool
    {
        $sppg = $this->route('sppg');

        return $sppg instanceof Sppg && $this->user()?->can('ubahSaldoAwal', $sppg) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'saldo_awal' => ['required', 'integer', 'min:0', 'max:'.self::NOMINAL_MAKS],
            'saldo_awal_tanggal' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'saldo_awal.integer' => 'Saldo awal harus rupiah bulat tanpa desimal.',
            'saldo_awal.min' => 'Saldo awal tidak boleh negatif.',
            'saldo_awal.max' => 'Saldo awal terlalu besar.',
            'saldo_awal_tanggal.before_or_equal' => 'Tanggal saldo awal tidak boleh di masa depan.',
        ];
    }
}
