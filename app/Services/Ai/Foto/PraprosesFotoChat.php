<?php

namespace App\Services\Ai\Foto;

use App\Services\Storage\GdPemrosesGambar;
use finfo;
use GdImage;

/**
 * Pra-pemrosesan foto chat bot SEBELUM dikirim ke model vision pihak ketiga (R-05):
 *  1. ukuran byte <= ai.input.photo_max_bytes (5 MB);
 *  2. MIME dari ISI berkas (finfo), bukan ekstensi/Content-Type/nama berkas Telegram; hanya JPEG/PNG/WebP.
 *     Berkas bertopeng (mis. skrip .php bernama .jpg) terdeteksi sebagai teks -> ditolak;
 *  3. header gambar (getimagesizefromstring) wajib sah, MIME-nya sama dengan finfo, dan jumlah piksel dibatasi
 *     (anti decompression bomb) SEBELUM di-decode;
 *  4. decode + encode ulang lewat GD menjadi JPEG: hanya piksel yang tersisa, sehingga EXIF (termasuk GPS), XMP,
 *     komentar, ICC, dan data sisipan (polyglot/trailer) terbuang. Orientasi EXIF diterapkan dulu; transparansi
 *     diratakan ke latar putih; sisi terpanjang dibatasi photo_max_side_px (~1280 px);
 *  5. bila base64 hasil masih > vision_max_base64_bytes (BELUM TERVERIFIKASI untuk model Preview), kualitas lalu
 *     dimensi diturunkan bertahap (photo_fallback_steps); tetap besar -> FotoChatDitolak "file terlalu besar".
 * Foto asli maupun hasil TIDAK disimpan ke disk (hanya memori job); lampiran permanen tetap lewat SecureUploadService.
 * Ekstensi: GD (sudah ada di Dockerfile: --with-jpeg --with-webp); tanpa dependensi tambahan.
 */
class PraprosesFotoChat
{
    public function __construct(private readonly GdPemrosesGambar $gd) {}

    /** @throws FotoChatDitolak */
    public function proses(string $biner): FotoSiap
    {
        $ukuran = strlen($biner);

        if ($ukuran === 0) {
            throw FotoChatDitolak::rusak();
        }

        if ($ukuran > (int) config('ai.input.photo_max_bytes', 5 * 1024 * 1024)) {
            throw FotoChatDitolak::terlaluBesar();
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($biner) ?: '';

        if (! in_array($mime, (array) config('ai.input.photo_mimes', ['image/jpeg', 'image/png', 'image/webp']), true)) {
            throw FotoChatDitolak::format();
        }

        $info = @getimagesizefromstring($biner);

        // Header "ajaib" saja (mis. FFD8 + skrip) tanpa struktur gambar sah, atau jenis header berbeda dari isi.
        if ($info === false || ($info['mime'] ?? null) !== $mime || (int) $info[0] < 1 || (int) $info[1] < 1) {
            throw FotoChatDitolak::rusak();
        }

        if ((int) $info[0] * (int) $info[1] > (int) config('ai.input.photo_max_pixels', 16_000_000)) {
            throw FotoChatDitolak::terlaluBesar();
        }

        if (! GdPemrosesGambar::mendukung($mime) || ! GdPemrosesGambar::mendukung('image/jpeg')) {
            throw FotoChatDitolak::tanpaGd();
        }

        $gambar = @imagecreatefromstring($biner);

        if (! $gambar instanceof GdImage) {
            throw FotoChatDitolak::rusak();
        }

        if (! imageistruecolor($gambar)) {
            imagepalettetotruecolor($gambar);
        }

        if ($mime === 'image/jpeg') {
            $gambar = $this->gd->terapkanOrientasi($gambar, $biner);
        }

        try {
            return $this->kompres($gambar, $mime, $ukuran);
        } finally {
            imagedestroy($gambar);
        }
    }

    /** @throws FotoChatDitolak */
    private function kompres(GdImage $gambar, string $mime, int $ukuranAsli): FotoSiap
    {
        // Batas penyedia untuk base64 BELUM TERVERIFIKASI (lihat config ai.openai_compat.vision_max_base64_bytes).
        $batas = max(1, (int) config('ai.openai_compat.vision_max_base64_bytes', 4 * 1024 * 1024));
        $langkah = [[(int) config('ai.input.photo_max_side_px', 1280), (int) config('ai.input.photo_jpeg_quality', 82)]];

        foreach ((array) config('ai.input.photo_fallback_steps', []) as $l) {
            if (is_array($l) && count($l) === 2) {
                $langkah[] = [(int) $l[0], (int) $l[1]];
            }
        }

        foreach ($langkah as [$sisi, $kualitas]) {
            $kecil = $this->kanvas($gambar, max(64, $sisi));
            $jpeg = $this->jpeg($kecil, max(30, min(95, $kualitas)));
            $lebar = imagesx($kecil);
            $tinggi = imagesy($kecil);
            imagedestroy($kecil);

            if ($jpeg !== '' && 4 * (int) ceil(strlen($jpeg) / 3) <= $batas) {
                return new FotoSiap(base64_encode($jpeg), $mime, $ukuranAsli, strlen($jpeg), $lebar, $tinggi, $kualitas);
            }
        }

        throw FotoChatDitolak::fileTerlaluBesar();
    }

    /**
     * Kanvas BARU berlatar putih dengan sisi terpanjang <= $maks; gambar sumber disalin (resample) dengan blending
     * alfa, sehingga PNG/WebP transparan rata ke putih (JPEG tanpa alfa; latar hitam menyulitkan pembacaan nota).
     */
    private function kanvas(GdImage $gambar, int $maks): GdImage
    {
        $w = imagesx($gambar);
        $h = imagesy($gambar);
        $skala = max($w, $h) > $maks ? $maks / max($w, $h) : 1.0;
        $nw = max(1, (int) round($w * $skala));
        $nh = max(1, (int) round($h * $skala));
        $baru = imagecreatetruecolor($nw, $nh);

        if (! $baru instanceof GdImage) {
            throw FotoChatDitolak::rusak();
        }

        imagefill($baru, 0, 0, (int) imagecolorallocate($baru, 255, 255, 255));
        imagealphablending($baru, true);
        imagecopyresampled($baru, $gambar, 0, 0, 0, 0, $nw, $nh, $w, $h);

        return $baru;
    }

    private function jpeg(GdImage $gambar, int $kualitas): string
    {
        $aliran = fopen('php://temp', 'w+b');

        if ($aliran === false) {
            return '';
        }

        $ok = imagejpeg($gambar, $aliran, $kualitas);
        rewind($aliran);
        $hasil = $ok ? (string) stream_get_contents($aliran) : '';
        fclose($aliran);

        return $hasil;
    }
}
