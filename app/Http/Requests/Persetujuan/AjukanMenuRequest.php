<?php

namespace App\Http\Requests\Persetujuan;

use App\Models\Menu;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Ajukan menu ke investor. HANYA versi + catatan: harga, biaya, porsi, dan rincian dihitung server
 * (field lain diabaikan; controller memakai validated()). Policy dicek sebelum validasi (403, bukan 422).
 */
class AjukanMenuRequest extends FormRequest
{
    public function authorize(): bool
    {
        $menu = $this->route('menu');

        return $menu instanceof Menu && $this->user()->can('ajukan', $menu);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'versi' => ['required', 'integer', 'min:1'],
            'catatan_pengaju' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
