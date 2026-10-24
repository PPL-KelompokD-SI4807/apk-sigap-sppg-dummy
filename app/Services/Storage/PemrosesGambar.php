<?php

namespace App\Services\Storage;

/**
 * Tahap akhir unggahan: membersihkan gambar yang sudah lolos deteksi jenis/dimensi.
 *
 * Implementasi WAJIB melempar BerkasDitolak bila berkas tidak dapat dibersihkan dengan aman.
 */
interface PemrosesGambar
{
    /**
     * @param  string  $isi  byte berkas asli (sudah lolos deteksi jenis)
     * @param  string  $mime  MIME terdeteksi (image/jpeg|image/png|image/webp)
     *
     * @throws BerkasDitolak
     */
    public function proses(string $isi, string $mime, int $lebar, int $tinggi): GambarSiap;
}
