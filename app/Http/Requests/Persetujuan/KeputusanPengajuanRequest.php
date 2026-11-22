<?php

namespace App\Http\Requests\Persetujuan;

use App\Enums\StatusPengajuanMenu;
use App\Models\PengajuanMenu;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Keputusan investor. Catatan wajib (5-1000 karakter) untuk ditolak/perlu_revisi. Tidak menerima
 * harga/biaya/porsi. Policy `putuskan` dicek sebelum validasi (non-investor/investor lain -> 403).
 */
class KeputusanPengajuanRequest extends FormRequest
{
    /** Panjang catatan keputusan; juga dipakai Bot (BotKeputusanService) agar aturan hanya didefinisikan sekali. */
    public const CATATAN_MIN = 5;

    public const CATATAN_MAX = 1000;

    public function authorize(): bool
    {
        $pengajuan = $this->route('pengajuan');

        return $pengajuan instanceof PengajuanMenu && $this->user()->can('putuskan', $pengajuan);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $wajibCatatan = Rule::requiredIf(in_array($this->input('keputusan'), [StatusPengajuanMenu::Ditolak->value, StatusPengajuanMenu::PerluRevisi->value], true));

        return [
            'keputusan' => ['required', 'string', Rule::in(StatusPengajuanMenu::keputusan())],
            'catatan' => [$wajibCatatan, 'nullable', 'string', 'min:'.self::CATATAN_MIN, 'max:'.self::CATATAN_MAX],
            'versi_menu' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['versi_menu' => 'versi menu'];
    }
}
