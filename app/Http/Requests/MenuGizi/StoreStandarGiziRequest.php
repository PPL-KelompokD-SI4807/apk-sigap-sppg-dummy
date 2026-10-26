<?php

namespace App\Http\Requests\MenuGizi;

use App\Enums\KelompokSasaran;
use App\Models\StandarGizi;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreStandarGiziRequest extends FormRequest
{
    public const ZAT = ['energi', 'protein', 'lemak', 'karbohidrat', 'serat'];

    /** Policy dicek sebelum validasi agar peran tanpa hak mendapat 403 (bukan 422). */
    public function authorize(): bool
    {
        $standar = $this->route('standar_gizi');

        return $standar instanceof StandarGizi
            ? $this->user()->can('update', $standar)
            : $this->user()->can('create', StandarGizi::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $wajib = $this->isMethod('POST') ? 'required' : 'sometimes';
        $rules = ['kelompok_sasaran' => [$wajib, Rule::enum(KelompokSasaran::class)]];

        foreach (self::ZAT as $zat) {
            $rules["{$zat}_min"] = [$wajib, 'numeric', 'min:0', 'max:99999'];
            $rules["{$zat}_max"] = [$wajib, 'numeric', 'min:0', 'max:99999'];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $model = $this->route('standar_gizi');
            $lama = $model instanceof StandarGizi ? $model : null;

            foreach (self::ZAT as $zat) {
                $min = $this->input("{$zat}_min", $lama?->{"{$zat}_min"});
                $max = $this->input("{$zat}_max", $lama?->{"{$zat}_max"});

                if (is_numeric($min) && is_numeric($max) && (float) $max < (float) $min
                    && ! $validator->errors()->has("{$zat}_max") && ! $validator->errors()->has("{$zat}_min")) {
                    $validator->errors()->add("{$zat}_max", "Batas maksimum {$zat} tidak boleh lebih kecil dari minimum.");
                }
            }
        }];
    }
}
