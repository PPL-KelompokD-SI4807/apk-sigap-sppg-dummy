<?php

namespace App\Http\Requests\Lampiran;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /lampiran (multipart). Otorisasi pada record induk dilakukan controller (perlu record);
 * isi berkas diverifikasi SecureUploadService (jenis dari isi, bukan ekstensi/MIME klien).
 */
class StoreLampiranRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'modul' => ['required', 'string', Rule::in(array_keys((array) config('unggahan.modul', [])))],
            'lampiranable_id' => ['required', 'integer', 'min:1'],
            'kategori' => ['nullable', 'string', 'regex:/^[a-z0-9_]{1,30}$/'],
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
        ];
    }
}
