<?php

namespace App\Services\Storage;

/**
 * Fallback tanpa GD: berkas TIDAK diubah, sehingga hanya gambar yang strukturnya bersih yang
 * diterima. Ditolak bila: memuat metadata (EXIF/XMP/komentar/teks), ada data setelah penanda
 * akhir (trailer/polyglot), struktur/CRC rusak, animasi, atau sisi terpanjang melebihi
 * maks_sisi_simpan_px (tidak dapat diperkecil). Mini App sudah mengompres via canvas sehingga
 * foto normal dari kamera lolos.
 */
class KetatPemrosesGambar implements PemrosesGambar
{
    /** Chunk PNG yang aman (tanpa teks/metadata). */
    private const CHUNK_PNG = ['IHDR', 'PLTE', 'IDAT', 'IEND', 'tRNS', 'gAMA', 'cHRM', 'sRGB', 'iCCP', 'sBIT', 'pHYs', 'bKGD'];

    /** Chunk WebP yang aman (tanpa EXIF/XMP/animasi). */
    private const CHUNK_WEBP = ['VP8 ', 'VP8L', 'VP8X', 'ALPH', 'ICCP'];

    public function proses(string $isi, string $mime, int $lebar, int $tinggi): GambarSiap
    {
        $maks = (int) config('unggahan.maks_sisi_simpan_px', 1600);

        if ($maks > 0 && max($lebar, $tinggi) > $maks) {
            throw new BerkasDitolak("Sisi terpanjang gambar maksimal {$maks} px.");
        }

        match ($mime) {
            'image/jpeg' => $this->periksaJpeg($isi),
            'image/png' => $this->periksaPng($isi),
            'image/webp' => $this->periksaWebp($isi),
            default => throw new BerkasDitolak('Jenis berkas tidak didukung.'),
        };

        return new GambarSiap($isi, $mime, (string) config("unggahan.mime.{$mime}"), $lebar, $tinggi, false);
    }

    private function periksaJpeg(string $b): void
    {
        $n = strlen($b);

        if ($n < 4 || substr($b, 0, 2) !== "\xFF\xD8" || substr($b, -2) !== "\xFF\xD9") {
            throw new BerkasDitolak('Struktur JPEG tidak valid atau ada data setelah akhir gambar.');
        }

        $i = 2;

        while ($i + 4 <= $n) {
            if ($b[$i] !== "\xFF") {
                throw new BerkasDitolak('Struktur JPEG tidak valid.');
            }

            $penanda = ord($b[$i + 1]);

            if ($penanda === 0xFF) { // padding
                $i++;

                continue;
            }

            $panjang = unpack('n', substr($b, $i + 2, 2))[1];

            if ($panjang < 2 || $i + 2 + $panjang > $n) {
                throw new BerkasDitolak('Struktur JPEG tidak valid.');
            }

            // APP1..APP13, APP15 (EXIF/XMP/IPTC/dll.) dan COM ditolak. APP0 (JFIF) & APP14 (Adobe) diizinkan.
            if (($penanda >= 0xE1 && $penanda <= 0xED) || $penanda === 0xEF || $penanda === 0xFE) {
                throw new BerkasDitolak('Gambar memuat metadata (EXIF/komentar). Unggah ulang dari kamera Mini App.');
            }

            if ($penanda === 0xDA) { // SOS: sisa = data entropi sampai EOI (sudah dicek di akhir berkas)
                $akhir = strpos($b, "\xFF\xD9", $i + 2 + $panjang);

                if ($akhir === false) {
                    throw new BerkasDitolak('Struktur JPEG tidak valid.');
                }

                // Data entropi meng-escape 0xFF (FF00/RSTn) dan penanda antar-scan progresif bukan
                // EOI, sehingga EOI pertama setelah SOS harus tepat di akhir berkas; selain itu = trailer.
                if ($akhir + 2 !== $n) {
                    throw new BerkasDitolak('Ada data tersembunyi setelah akhir gambar.');
                }

                return;
            }

            $i += 2 + $panjang;
        }

        throw new BerkasDitolak('Struktur JPEG tidak valid.');
    }

    private function periksaPng(string $b): void
    {
        $n = strlen($b);
        $i = 8;
        $akhir = false;

        while ($i + 12 <= $n) {
            $panjang = unpack('N', substr($b, $i, 4))[1];
            $jenis = substr($b, $i + 4, 4);

            if ($panjang > $n || $i + 12 + $panjang > $n) {
                throw new BerkasDitolak('Struktur PNG tidak valid.');
            }

            $data = substr($b, $i + 8, $panjang);
            $crc = unpack('N', substr($b, $i + 8 + $panjang, 4))[1];

            if (crc32($jenis.$data) !== $crc) {
                throw new BerkasDitolak('Struktur PNG rusak (CRC).');
            }

            if (! in_array($jenis, self::CHUNK_PNG, true)) {
                throw new BerkasDitolak('Gambar memuat metadata atau data tambahan yang tidak diizinkan.');
            }

            $i += 12 + $panjang;

            if ($jenis === 'IEND') {
                $akhir = true;
                break;
            }
        }

        if (! $akhir || $i !== $n) {
            throw new BerkasDitolak('Ada data tersembunyi setelah akhir gambar.');
        }
    }

    private function periksaWebp(string $b): void
    {
        $n = strlen($b);

        if ($n < 12 || unpack('V', substr($b, 4, 4))[1] + 8 !== $n) {
            throw new BerkasDitolak('Struktur WebP tidak valid atau ada data setelah akhir gambar.');
        }

        $i = 12;

        while ($i + 8 <= $n) {
            $jenis = substr($b, $i, 4);
            $panjang = unpack('V', substr($b, $i + 4, 4))[1];

            if (! in_array($jenis, self::CHUNK_WEBP, true)) {
                throw new BerkasDitolak('Gambar memuat metadata atau animasi yang tidak diizinkan.');
            }

            $i += 8 + $panjang + ($panjang % 2);

            if ($i > $n) {
                throw new BerkasDitolak('Struktur WebP tidak valid.');
            }
        }

        if ($i !== $n) {
            throw new BerkasDitolak('Struktur WebP tidak valid.');
        }
    }
}
