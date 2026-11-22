<?php

namespace App\Http\Requests\Lampiran;

use App\Models\Sppg;
use App\Support\Rbac;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /lampiran (multipart). Dua mode:
 *  - Terikat: `lampiranable_id` diisi -> berkas langsung menempel ke record induk yang sudah ada.
 *  - Tertunda: tanpa `lampiranable_id` (hanya modul ber-'tertunda' di config/unggahan.php) -> berkas
 *    disimpan tanpa induk; klien mengirim id-nya lewat `lampiran_ids` pada store/update modul.
 *    Admin (tanpa SPPG) wajib menyebut `sppg_id`.
 * Otorisasi pada record induk/hak unggah tertunda dilakukan controller; isi berkas diverifikasi
 * SecureUploadService (jenis dari isi, bukan ekstensi/MIME klien).
 */
class StoreLampiranRequest extends FormRequest
{
    /** Pengguna baca saja (investor, R-06a) tidak pernah mengunggah; sisanya diputuskan controller. */
    public function authorize(): bool
    {
        return $this->user() !== null && ! Rbac::readOnly($this->user());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $modul = (string) $this->input('modul');
        $def = (array) config("unggahan.modul.{$modul}");
        $kategori = $def['kategori'] ?? null;
        $tertunda = ! $this->filled('lampiranable_id');
        $admin = $this->user()?->isAdmin() === true;

        return [
            'modul' => ['required', 'string', Rule::in(array_keys((array) config('unggahan.modul', [])))],
            'lampiranable_id' => ['nullable', 'integer', 'min:1'],
            'sppg_id' => $admin && $tertunda
                ? ['required', 'integer', Rule::exists((new Sppg)->getTable(), 'id')->whereNull('deleted_at')]
                : ['exclude'],
            'kategori' => is_array($kategori)
                ? ['nullable', 'string', Rule::in($kategori)]
                : ['nullable', 'string', 'regex:/^[a-z0-9_]{1,30}$/'],
            'berkas' => ['required', 'file', 'max:'.intdiv((int) config('unggahan.maks_byte', 5242880), 1024)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'modul.in' => 'Modul lampiran tidak dikenal.',
            'berkas.required' => 'Berkas gambar wajib diunggah.',
            'berkas.file' => 'Berkas gagal diunggah.',
            'berkas.uploaded' => 'Berkas gagal diunggah (melebihi batas server?).',
            'berkas.max' => 'Ukuran berkas maksimal 5 MB.',
            'kategori.regex' => 'Kategori hanya huruf kecil, angka, dan garis bawah.',
            'kategori.in' => 'Kategori lampiran tidak sesuai untuk modul ini.',
        ];
    }
}
