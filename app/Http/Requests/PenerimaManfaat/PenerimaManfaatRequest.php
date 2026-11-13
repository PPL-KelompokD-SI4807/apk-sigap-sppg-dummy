<?php

namespace App\Http\Requests\PenerimaManfaat;

use App\Enums\JenisKelamin;
use App\Enums\JenisSatuanPendidikan;
use App\Enums\JenjangPenerima;
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
            'jenis_kelamin' => ['nullable', Rule::enum(JenisKelamin::class)],
            'jenjang' => ['nullable', Rule::enum(JenjangPenerima::class)],
            'jenis_satuan' => ['nullable', Rule::enum(JenisSatuanPendidikan::class)],
            'kelas_keterangan' => ['nullable', 'string', 'max:100'],
            'catatan_alergi' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ] + $this->aiDraftRules();
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $this->periksaKlasifikasi($validator);

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

    /**
     * Konsistensi klasifikasi Lampiran 30: jenjang ATS hanya untuk kelompok `ats`; `siswa` memakai jenjang sekolah;
     * jenjang/jenis satuan tidak berlaku bagi kelompok lain. Hanya diperiksa bila field itu dikirim.
     */
    private function periksaKlasifikasi(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty() || ! ($this->filled('jenjang') || $this->filled('jenis_satuan'))) {
            return;
        }

        $current = $this->route('penerimaManfaat');
        $kelompok = KelompokSasaran::tryFrom((string) $this->input('kelompok_sasaran'))
            ?? ($current instanceof PenerimaManfaat ? $current->kelompok_sasaran : null);

        if ($this->filled('jenjang')) {
            $jenjang = JenjangPenerima::from((string) $this->input('jenjang'));
            $cocok = match ($kelompok) {
                KelompokSasaran::Siswa => ! $jenjang->untukAts(),
                KelompokSasaran::Ats => $jenjang->untukAts(),
                default => false,
            };

            if (! $cocok) {
                $validator->errors()->add('jenjang', 'Jenjang tidak sesuai dengan kelompok sasaran (siswa: PAUD/TK sampai SMA; ATS: usia ATS; kelompok lain: kosongkan).');
            }
        }

        if ($this->filled('jenis_satuan') && $kelompok !== KelompokSasaran::Siswa) {
            $validator->errors()->add('jenis_satuan', 'Jenis satuan pendidikan hanya untuk kelompok siswa.');
        }
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
