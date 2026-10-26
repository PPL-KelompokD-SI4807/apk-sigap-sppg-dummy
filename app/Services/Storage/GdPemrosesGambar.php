<?php

namespace App\Services\Storage;

use GdImage;

/**
 * Encode ulang lewat GD: hanya piksel yang dipertahankan, sehingga EXIF (termasuk GPS),
 * XMP, komentar, ICC, dan data sisipan (polyglot/trailer) terbuang. Orientasi EXIF diterapkan
 * lebih dulu agar foto tidak terbalik, lalu gambar diperkecil ke maks_sisi_simpan_px.
 */
class GdPemrosesGambar implements PemrosesGambar
{
    public static function tersedia(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatefromstring');
    }

    /** True bila GD build ini dapat membaca dan menulis MIME tersebut. */
    public static function mendukung(string $mime): bool
    {
        if (! self::tersedia()) {
            return false;
        }

        return match ($mime) {
            'image/jpeg' => (imagetypes() & IMG_JPEG) !== 0 && function_exists('imagejpeg'),
            'image/png' => (imagetypes() & IMG_PNG) !== 0 && function_exists('imagepng'),
            'image/webp' => (imagetypes() & IMG_WEBP) !== 0 && function_exists('imagewebp'),
            default => false,
        };
    }

    public function proses(string $isi, string $mime, int $lebar, int $tinggi): GambarSiap
    {
        $this->pastikanMemori($lebar, $tinggi);

        $gambar = @imagecreatefromstring($isi);

        if (! $gambar instanceof GdImage) {
            throw new BerkasDitolak('Gambar rusak atau tidak dapat dibaca.');
        }

        if (! imageistruecolor($gambar)) {
            imagepalettetotruecolor($gambar);
        }

        if ($mime === 'image/jpeg') {
            $gambar = $this->terapkanOrientasi($gambar, $isi);
        }

        $gambar = $this->perkecil($gambar, (int) config('unggahan.maks_sisi_simpan_px', 1600), $mime !== 'image/jpeg');
        $hasil = $this->encode($gambar, $mime);
        imagedestroy($gambar);

        $info = @getimagesizefromstring($hasil);

        if ($hasil === '' || $info === false || ($info['mime'] ?? null) !== $mime) {
            throw new BerkasDitolak('Gambar gagal diproses ulang.');
        }

        return new GambarSiap($hasil, $mime, (string) config("unggahan.mime.{$mime}"), (int) $info[0], (int) $info[1], true);
    }

    private function pastikanMemori(int $lebar, int $tinggi): void
    {
        // Truecolor GD ~4 byte/piksel + salinan hasil perkecil + overhead.
        $butuh = ($lebar * $tinggi * 5) + (64 * 1024 * 1024);
        $batas = $this->keByte((string) ini_get('memory_limit'));

        if ($batas > 0 && memory_get_usage(true) + $butuh > $batas) {
            if (@ini_set('memory_limit', (string) (memory_get_usage(true) + $butuh)) === false) {
                throw new BerkasDitolak('Resolusi gambar terlalu besar untuk diproses.');
            }
        }
    }

    private function keByte(string $nilai): int
    {
        $nilai = trim($nilai);

        if ($nilai === '' || $nilai === '-1') {
            return -1;
        }

        $angka = (int) $nilai;

        return match (strtolower(substr($nilai, -1))) {
            'g' => $angka * 1024 ** 3,
            'm' => $angka * 1024 ** 2,
            'k' => $angka * 1024,
            default => $angka,
        };
    }

    private function terapkanOrientasi(GdImage $gambar, string $isi): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $gambar;
        }

        $aliran = fopen('php://temp', 'w+b');

        if ($aliran === false) {
            return $gambar;
        }

        fwrite($aliran, $isi);
        rewind($aliran);
        $exif = @exif_read_data($aliran);
        fclose($aliran);

        $orientasi = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $putar = fn (GdImage $g, int $sudut): GdImage => ($r = imagerotate($g, $sudut, 0)) instanceof GdImage ? $r : $g;

        switch ($orientasi) {
            case 2:
                imageflip($gambar, IMG_FLIP_HORIZONTAL);
                break;
            case 3:
                $gambar = $putar($gambar, 180);
                break;
            case 4:
                imageflip($gambar, IMG_FLIP_VERTICAL);
                break;
            case 5:
                imageflip($gambar, IMG_FLIP_VERTICAL);
                $gambar = $putar($gambar, -90);
                break;
            case 6:
                $gambar = $putar($gambar, -90);
                break;
            case 7:
                imageflip($gambar, IMG_FLIP_HORIZONTAL);
                $gambar = $putar($gambar, -90);
                break;
            case 8:
                $gambar = $putar($gambar, 90);
                break;
        }

        return $gambar;
    }

    private function perkecil(GdImage $gambar, int $maks, bool $alfa): GdImage
    {
        $w = imagesx($gambar);
        $h = imagesy($gambar);

        if ($alfa) {
            imagealphablending($gambar, false);
            imagesavealpha($gambar, true);
        }

        if ($maks < 1 || max($w, $h) <= $maks) {
            return $gambar;
        }

        $skala = $maks / max($w, $h);
        $nw = max(1, (int) round($w * $skala));
        $nh = max(1, (int) round($h * $skala));

        $baru = imagecreatetruecolor($nw, $nh);

        if (! $baru instanceof GdImage) {
            throw new BerkasDitolak('Gambar gagal diproses ulang.');
        }

        if ($alfa) {
            imagealphablending($baru, false);
            imagesavealpha($baru, true);
            imagefill($baru, 0, 0, imagecolorallocatealpha($baru, 0, 0, 0, 127));
        }

        imagecopyresampled($baru, $gambar, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($gambar);

        return $baru;
    }

    private function encode(GdImage $gambar, string $mime): string
    {
        $aliran = fopen('php://temp', 'w+b');

        if ($aliran === false) {
            throw new BerkasDitolak('Gambar gagal diproses ulang.');
        }

        $ok = match ($mime) {
            'image/jpeg' => imagejpeg($gambar, $aliran, (int) config('unggahan.kualitas_jpeg', 85)),
            'image/png' => imagepng($gambar, $aliran, 6),
            'image/webp' => imagewebp($gambar, $aliran, (int) config('unggahan.kualitas_webp', 85)),
            default => false,
        };

        rewind($aliran);
        $hasil = $ok ? (string) stream_get_contents($aliran) : '';
        fclose($aliran);

        return $hasil;
    }
}
