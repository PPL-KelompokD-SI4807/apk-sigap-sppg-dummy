<?php

namespace App\Services\Ai\Foto;

/**
 * Foto chat yang sudah diproses ulang (JPEG tanpa metadata, sisi terpanjang dibatasi) dan siap dikirim ke model
 * vision. Hanya angka yang boleh di-log (konteksLog); isi gambar/base64 TIDAK PERNAH di-log.
 */
final class FotoSiap
{
    public function __construct(
        public readonly string $base64,
        public readonly string $mimeAsli,
        public readonly int $ukuranAsli,
        public readonly int $ukuranHasil,
        public readonly int $lebar,
        public readonly int $tinggi,
        public readonly int $kualitas,
    ) {}

    /** @return array{media_type: string, data: string} format AiInput::photo() */
    public function gambar(): array
    {
        return ['media_type' => 'image/jpeg', 'data' => $this->base64];
    }

    /** @return array<string, int|string> */
    public function konteksLog(): array
    {
        return [
            'mime_asli' => $this->mimeAsli,
            'ukuran_asli' => $this->ukuranAsli,
            'ukuran_hasil' => $this->ukuranHasil,
            'panjang_kiriman' => strlen($this->base64),
            'lebar' => $this->lebar,
            'tinggi' => $this->tinggi,
            'kualitas' => $this->kualitas,
        ];
    }
}
