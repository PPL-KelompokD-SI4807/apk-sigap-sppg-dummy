<?php

namespace App\Http\Requests\BuktiDistribusi;

use App\Enums\Role;
use Illuminate\Validation\Validator;

/**
 * Aturan bersama Store/Update bukti distribusi (dipakai juga dry-run draf Agen AI).
 * Berkas foto/berita_acara diverifikasi isi oleh SecureUploadService di controller.
 */
trait BuktiDistribusiRules
{
    /** @return array<string, list<mixed>> */
    protected function aturanIsi(bool $wajib): array
    {
        $req = $wajib ? 'required' : 'sometimes';
        $relawan = $this->user()?->role === Role::Relawan;
        $maksKb = intdiv((int) config('unggahan.maks_byte', 5242880), 1024);
        $nanti = now()->addMinutes(5)->toDateTimeString();

        return [
            'porsi_diterima' => [$req, 'integer', 'min:0', 'max:100000'],
            'nama_pic' => [$req, 'string', 'max:100'],
            'no_berita_acara' => ['sometimes', 'nullable', 'string', 'max:50'],
            'catatan_selisih' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'catatan' => ['sometimes', 'nullable', 'string', 'max:2000'],
            // Relawan: waktu tiba = waktu server (diabaikan bila dikirim). Kepala: koreksi manual.
            'waktu_tiba' => $relawan ? ['exclude'] : ['sometimes', 'nullable', 'date', 'before_or_equal:'.$nanti],
            'waktu_berangkat' => ['sometimes', 'nullable', 'date', 'before_or_equal:'.$nanti],
            'waktu_ambil_foto' => ['sometimes', 'nullable', 'date', 'before_or_equal:'.$nanti],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'foto' => [$wajib ? 'required' : 'sometimes', 'file', 'max:'.$maksKb],
            'berita_acara' => ['sometimes', 'nullable', 'file', 'max:'.$maksKb],
            'ai_draft_id' => ['nullable', 'uuid', 'exists:ai_drafts,uuid'],
        ];
    }

    /** Porsi diterima <= dikirim; selisih wajib dijelaskan (catatan_selisih). */
    protected function periksaPorsi(Validator $validator, ?int $porsiDikirim, mixed $porsiDiterima, mixed $catatanSelisih): void
    {
        if ($porsiDikirim === null || ! is_numeric($porsiDiterima)) {
            return;
        }

        $diterima = (int) $porsiDiterima;

        if ($diterima > $porsiDikirim) {
            $validator->errors()->add('porsi_diterima', "Porsi diterima tidak boleh melebihi porsi dikirim ({$porsiDikirim}).");
        } elseif ($diterima < $porsiDikirim && blank($catatanSelisih)) {
            $validator->errors()->add('catatan_selisih', 'Catatan selisih wajib diisi bila porsi diterima kurang dari porsi dikirim.');
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'jadwal_distribusi_id.required' => 'Jadwal distribusi wajib dipilih.',
            'jadwal_distribusi_id.exists' => 'Jadwal distribusi tidak ditemukan.',
            'porsi_diterima.required' => 'Porsi diterima wajib diisi.',
            'nama_pic.required' => 'Nama PIC penerima wajib diisi.',
            'foto.required' => 'Foto bukti serah terima wajib diunggah.',
            'foto.max' => 'Ukuran foto maksimal 5 MB.',
            'berita_acara.max' => 'Ukuran foto berita acara maksimal 5 MB.',
            'foto.uploaded' => 'Foto gagal diunggah.',
        ];
    }
}
