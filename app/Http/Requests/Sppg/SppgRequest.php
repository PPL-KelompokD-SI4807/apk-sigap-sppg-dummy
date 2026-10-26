<?php

namespace App\Http\Requests\Sppg;

use App\Enums\StatusOperasional;
use App\Http\Requests\Concerns\HasAiDraftId;
use App\Models\Sppg;
use App\Rules\NomorTelepon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Aturan validasi SPPG. `kode` hanya diterima dari Admin (Kepala SPPG: RU tanpa mengubah kode). */
abstract class SppgRequest extends FormRequest
{
    use HasAiDraftId;

    abstract protected function creating(): bool;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $req = $this->creating() ? 'required' : 'sometimes';
        $sppg = $this->route('sppg');

        $rules = [
            'nama' => [$req, 'string', 'max:255'],
            'alamat' => [$req, 'string', 'max:255'],
            'mitra_yayasan' => ['nullable', 'string', 'max:255'],
            'telepon' => ['nullable', new NomorTelepon],
            'kapasitas_porsi_harian' => [$req, 'integer', 'min:1', 'max:100000'],
            'status_operasional' => ['sometimes', Rule::enum(StatusOperasional::class)],
        ];

        if ($this->user()?->isAdmin()) {
            $rules['kode'] = [$req, 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('sppgs', 'kode')->ignore($sppg instanceof Sppg ? $sppg->getKey() : null)];
        }

        return $rules + $this->aiDraftRules();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'kapasitas_porsi_harian.min' => 'Kapasitas porsi harian harus lebih dari 0.',
            'kapasitas_porsi_harian.max' => 'Kapasitas porsi harian maksimal 100.000.',
            'kode.unique' => 'Kode SPPG sudah dipakai (termasuk SPPG yang telah dihapus).',
            'kode.regex' => 'Kode SPPG hanya boleh huruf, angka, titik, garis bawah, dan tanda hubung.',
        ];
    }
}
