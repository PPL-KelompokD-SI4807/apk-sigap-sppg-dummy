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
            // R07-1: identitas format BGN (Lampiran 30); semua opsional.
            'id_sppg_bgn' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9._\/-]+$/'],
            'kecamatan' => ['nullable', 'string', 'max:100'],
            'kab_kota' => ['nullable', 'string', 'max:100'],
            'provinsi' => ['nullable', 'string', 'max:100'],
            'no_va' => ['nullable', 'string', 'max:50', 'regex:/^[0-9][0-9 .-]*$/'],
            'nama_ketua_yayasan' => ['nullable', 'string', 'max:150', 'not_regex:/\d{8,}/'],
            // Pengaturan anggaran per SPPG; null = kode memakai nilai bawaan (8000/10000/6000000/6).
            'acuan_bahan_baku_kecil' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'acuan_bahan_baku_lainnya' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'insentif_fasilitas_per_hari' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'insentif_fasilitas_maks_hari_minggu' => ['nullable', 'integer', 'min:1', 'max:7'],
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
            'id_sppg_bgn.regex' => 'ID SPPG BGN hanya boleh huruf, angka, titik, garis miring, garis bawah, dan tanda hubung.',
            'no_va.regex' => 'Nomor rekening/Virtual Account hanya boleh angka, spasi, titik, atau tanda hubung.',
            'nama_ketua_yayasan.not_regex' => 'Nama ketua yayasan tidak boleh berisi nomor identitas.',
            'insentif_fasilitas_maks_hari_minggu.max' => 'Maksimal hari insentif fasilitas per minggu adalah 7.',
            'kode.unique' => 'Kode SPPG sudah dipakai (termasuk SPPG yang telah dihapus).',
            'kode.regex' => 'Kode SPPG hanya boleh huruf, angka, titik, garis bawah, dan tanda hubung.',
        ];
    }
}
