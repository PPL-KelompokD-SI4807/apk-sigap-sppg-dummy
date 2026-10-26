<?php

namespace App\Http\Requests\Pemasok;

use App\Models\BahanBaku;
use App\Models\Pemasok;
use App\Models\Scopes\SppgScope;
use App\Services\Persetujuan\BiayaMenuService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Catat harga bahan dari pemasok (append-only). Pemasok dan bahan wajib satu SPPG. gram_per_satuan
 * wajib bila satuan bahan bukan kg/g/gram/liter/l/ml. Tanggal berlaku: hari ini -1 tahun s.d. +1 tahun.
 */
class StoreHargaPemasokRequest extends FormRequest
{
    public function authorize(): bool
    {
        $pemasok = $this->route('pemasok');

        return $pemasok instanceof Pemasok && $this->user()->can('catatHarga', $pemasok);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $pemasok = $this->route('pemasok');
        $sppgId = $pemasok instanceof Pemasok ? (int) $pemasok->sppg_id : 0;
        $hariIni = now((string) config('operasional.timezone', 'Asia/Jakarta'));

        return [
            'bahan_baku_id' => ['required', 'integer', Rule::exists('bahan_bakus', 'id')->where('sppg_id', $sppgId)->whereNull('deleted_at')],
            'harga' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'gram_per_satuan' => ['nullable', 'numeric', 'decimal:0,3', 'min:0.001', 'max:1000000'],
            'berlaku_mulai' => [
                'required', 'date_format:Y-m-d',
                'after_or_equal:'.$hariIni->copy()->subYear()->toDateString(),
                'before_or_equal:'.$hariIni->copy()->addYear()->toDateString(),
            ],
            'catatan' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $pemasok = $this->route('pemasok');
            $bahan = BahanBaku::withoutGlobalScope(SppgScope::class)
                ->where('sppg_id', $pemasok->sppg_id)->whereKey((int) $this->input('bahan_baku_id'))->first();

            if ($bahan !== null && ! BiayaMenuService::satuanBaku($bahan->satuan) && $this->input('gram_per_satuan') === null) {
                $validator->errors()->add('gram_per_satuan', sprintf('Isi gram per satuan: satuan bahan "%s" tidak dapat dikonversi otomatis ke gram.', $bahan->satuan));
            }
        }];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['bahan_baku_id' => 'bahan baku', 'gram_per_satuan' => 'gram per satuan', 'berlaku_mulai' => 'tanggal berlaku'];
    }
}
