<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;

/**
 * Berkas uji unggahan (fiktif). Gambar dibuat dengan GD bila tersedia; tanpa GD dipakai gambar
 * kecil tertanam (4x3 px) sehingga tes validasi tetap berjalan.
 */
trait GambarUji
{
    private const JPEG_KECIL = '/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gODAK/9sAQwAGBAUGBQQGBgUGBwcGCAoQCgoJCQoUDg8MEBcUGBgXFBYWGh0lHxobIxwWFiAsICMmJykqKRkfLTAtKDAlKCko/9sAQwEHBwcKCAoTCgoTKBoWGigoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgo/8AAEQgAAwAEAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A5OiiivCP1Y//2Q==';

    private const PNG_KECIL = 'iVBORw0KGgoAAAANSUhEUgAAAAQAAAADCAIAAAA7ljmRAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFElEQVQImWM8YWTEAANMDEgAhQMALI4BMhTb8T4AAAAASUVORK5CYII=';

    private const WEBP_KECIL = 'UklGRjoAAABXRUJQVlA4IC4AAACwAQCdASoEAAMAAUAmJaACdLoABDAAAP7vUS/xbSOhTIf/cHH/YOP+wcfumAAA';

    protected function gdAda(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatetruecolor');
    }

    protected function lewatiTanpaGd(): void
    {
        if (! $this->gdAda()) {
            $this->markTestSkipped('Ekstensi gd tidak tersedia.');
        }
    }

    protected function bytesJpeg(int $w = 4, int $h = 3): string
    {
        if (! $this->gdAda()) {
            return base64_decode(self::JPEG_KECIL);
        }

        return $this->gdEncode('jpeg', $w, $h);
    }

    protected function bytesPng(int $w = 4, int $h = 3): string
    {
        if (! $this->gdAda()) {
            return base64_decode(self::PNG_KECIL);
        }

        return $this->gdEncode('png', $w, $h);
    }

    protected function bytesWebp(): string
    {
        return base64_decode(self::WEBP_KECIL);
    }

    /** JPEG dengan segmen APP1 EXIF fiktif (memuat penanda GPS palsu) disisipkan setelah SOI. */
    protected function bytesJpegDenganExif(int $w = 40, int $h = 30): string
    {
        $isi = 'Exif'."\0\0".'II*'."\0".'SIGAP-GPS-FIKTIF-6.2000S-106.8166E';
        $segmen = "\xFF\xE1".pack('n', strlen($isi) + 2).$isi;
        $jpeg = $this->bytesJpeg($w, $h);

        return substr($jpeg, 0, 2).$segmen.substr($jpeg, 2);
    }

    /** JPEG tanpa segmen komentar (mirip keluaran canvas Mini App). */
    protected function bytesJpegBersih(): string
    {
        $b = $this->bytesJpeg();
        $hasil = substr($b, 0, 2);
        $i = 2;

        while ($i + 4 <= strlen($b)) {
            $penanda = ord($b[$i + 1]);
            $panjang = unpack('n', substr($b, $i + 2, 2))[1];

            if ($penanda === 0xDA) {
                return $hasil.substr($b, $i);
            }

            if ($penanda !== 0xFE) {
                $hasil .= substr($b, $i, 2 + $panjang);
            }

            $i += 2 + $panjang;
        }

        return $b;
    }

    protected function berkas(string $isi, string $nama = 'foto.jpg', string $mimeKlien = 'image/jpeg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nama, $isi)->mimeType($mimeKlien);
    }

    protected function fotoJpeg(string $nama = 'foto.jpg'): UploadedFile
    {
        return $this->berkas($this->bytesJpeg(64, 48), $nama);
    }

    private function gdEncode(string $jenis, int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 30, 140, 90));
        imagefilledrectangle($img, 0, 0, intdiv($w, 2), intdiv($h, 2), imagecolorallocate($img, 220, 200, 40));
        ob_start();
        $jenis === 'png' ? imagepng($img) : imagejpeg($img, null, 80);
        $hasil = (string) ob_get_clean();
        imagedestroy($img);

        return $hasil;
    }
}
