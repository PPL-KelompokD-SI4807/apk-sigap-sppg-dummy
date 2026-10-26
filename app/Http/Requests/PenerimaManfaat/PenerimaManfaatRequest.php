<?php

namespace App\Http\Requests\PenerimaManfaat;

use App\Enums\KelompokSasaran;
use App\Http\Requests\Concerns\HasAiDraftId;
use App\Models\PenerimaManfaat;
use App\Models\TitikLayanan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Aturan validasi penerima manfaat. Tanpa NIK (UU PDP): nama hanya inisial/nama minimal dan
 * deret 8+ angka berurutan ditolak. sppg_id record diturunkan dari titik layanan (bukan dari body).
 */
abstract class PenerimaManfaatRequest extends FormRequest
{
    use HasAiDraftId;

    abstract protected function creating(): bool;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $req = $this->creating() ? 'required' : 'sometimes';
        $user = $this->user();

        $titik = Rule::exists('titik_layanans', 'id')->whereNull('deleted_at');

        if ($user !== null && ! $user->isAdmin()) {
            $titik = $titik->where('sppg_id', $user->sppg_id);
        }

        return [
            'titik_layanan_id' => [$req, 'integer', $titik],
            'nama' => [$req, 'string', 'max:100', 'not_regex:/\d{8,}/'],
            'kelompok_sasaran' => [$req, Rule::enum(KelompokSasaran::class)],
            'kelas_keterangan' => ['nullable', 'string', 'max:100'],
            'catatan_alergi' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ] + $this->aiDraftRules();
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->filled('titik_layanan_id')) {
                return;
            }

            $current = $this->route('penerimaManfaat');
            $unchanged = $current instanceof PenerimaManfaat
                && (int) $current->titik_layanan_id === (int) $this->input('titik_layanan_id');

            if ($unchanged) {
                return;
            }

            $titik = TitikLayanan::query()->find($this->input('titik_layanan_id'));

            if ($titik !== null && ! $titik->is_active) {
                $validator->errors()->add('titik_layanan_id', 'Titik layanan nonaktif tidak dapat menerima penerima manfaat baru.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'titik_layanan_id.exists' => 'Titik layanan tidak ditemukan.',
            'nama.not_regex' => 'Nama hanya boleh inisial/nama minimal; jangan memasukkan NIK atau nomor identitas.',
        ];
    }
}
