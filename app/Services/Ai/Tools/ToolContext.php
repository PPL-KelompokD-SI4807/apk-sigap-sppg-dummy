<?php

namespace App\Services\Ai\Tools;

use App\Enums\JenisInputAi;

/**
 * Konteks eksekusi tool (interaksi AI yang sedang berjalan).
 *
 * - `teksPengguna`: teks pesan pengguna yang sudah dibersihkan (ketikan/transkrip/caption + hasil baca foto), hanya
 *   untuk penjagaan server (mis. ResolverReferensi memeriksa apakah nama yang dikirim LLM memang ditulis pengguna).
 *   Tidak pernah disimpan atau dikirim ulang ke LLM.
 * - `galatArgumen`: galat per kolom yang ditemukan SERVER sebelum tool dijalankan (argumen tidak lolos skema, atau
 *   kolom yang ditolak penyedia LLM pada percobaan sebelumnya). Kolomnya sudah dibuang dari argumen; tool tulis
 *   menyimpannya di draf sebagai galat "perlu diperbaiki" (GalatDraf).
 */
final class ToolContext
{
    /**
     * @param  array<string, list<string>>  $galatArgumen
     */
    public function __construct(
        public readonly ?int $interactionId = null,
        public readonly JenisInputAi $inputType = JenisInputAi::Teks,
        public readonly ?string $teksPengguna = null,
        public readonly array $galatArgumen = [],
    ) {}

    /** @param array<string, list<string>> $galat */
    public function denganGalatArgumen(array $galat): self
    {
        return new self($this->interactionId, $this->inputType, $this->teksPengguna, $galat);
    }
}
