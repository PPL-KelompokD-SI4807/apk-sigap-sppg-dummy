<?php

namespace App\Services\Storage;

/**
 * Gambar yang sudah lolos validasi dan siap disimpan (hasil SecureUploadService::siapkan()).
 * Byte di sini adalah hasil encode ulang (GD) atau berkas asli yang lolos mode ketat.
 */
final class GambarSiap
{
    public readonly int $ukuran;

    public readonly string $sha256;

    public function __construct(
        public readonly string $isi,
        public readonly string $mime,
        public readonly string $ekstensi,
        public readonly int $lebar,
        public readonly int $tinggi,
        public readonly bool $diencodeUlang,
    ) {
        $this->ukuran = strlen($isi);
        $this->sha256 = hash('sha256', $isi);
    }
}
