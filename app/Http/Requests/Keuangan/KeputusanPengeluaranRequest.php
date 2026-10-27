<?php

namespace App\Http\Requests\Keuangan;

use App\Enums\StatusPengajuanPengeluaran;
use App\Models\Pengeluaran;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Keputusan investor atas pengajuan pengeluaran (R-06b-2). Catatan wajib (5..500) saat ditolak. Tidak menerima
 * nominal/kategori/status. Policy `putuskan` dicek sebelum validasi (non-investor/investor lain/admin -> 403).
 */
class KeputusanPengeluaranRequest extends FormRequest
{
    /** Panjang catatan keputusan; juga dipakai Bot (BotKeputusanService) agar aturan hanya didefinisikan sekali. */
    public const CATATAN_MIN = 5;

    public const CATATAN_MAX = 500;

    public function authorize(): bool
    {
        $pengeluaran = $this->route('pengeluaran');

        return $pengeluaran instanceof Pengeluaran && $this->user()->can('putuskan', $pengeluaran);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'keputusan' => ['required', 'string', Rule::in(StatusPengajuanPengeluaran::keputusan())],
            'catatan' => [Rule::requiredIf($this->input('keputusan') === StatusPengajuanPengeluaran::Ditolak->value), 'nullable', 'string', 'min:'.self::CATATAN_MIN, 'max:'.self::CATATAN_MAX],
            'versi' => ['required', 'integer', 'min:1'],
        ];
    }
}
