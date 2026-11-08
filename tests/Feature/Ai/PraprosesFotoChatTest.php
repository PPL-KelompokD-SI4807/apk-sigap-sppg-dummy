<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\Foto\FotoChatDitolak;
use App\Services\Ai\Foto\PraprosesFotoChat;
use App\Services\Storage\BerkasDitolak;
use App\Services\Storage\GdPemrosesGambar;
use GdImage;
use Tests\TestCase;

/** Pra-pemrosesan foto chat: MIME dari isi, encode ulang JPEG, ukuran, penurunan bertahap, batas piksel. */
class PraprosesFotoChatTest extends TestCase
{
    private function proses(string $biner)
    {
        return app(PraprosesFotoChat::class)->proses($biner);
    }

    private static function encode(GdImage $g, string $jenis, int $kualitas = 90): string
    {
        ob_start();
        match ($jenis) {
            'png' => imagepng($g),
            'webp' => imagewebp($g, null, $kualitas),
            'gif' => imagegif($g),
            default => imagejpeg($g, null, $kualitas),
        };
        imagedestroy($g);

        return (string) ob_get_clean();
    }

    private static function gambar(int $w, int $h, bool $derau = false): GdImage
    {
        $g = imagecreatetruecolor($w, $h);
        imagefill($g, 0, 0, (int) imagecolorallocate($g, 240, 240, 240));

        if ($derau) {
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    imagesetpixel($g, $x, $y, random_int(0, 0xFFFFFF));
                }
            }
        }

        return $g;
    }

    private function assertDitolak(string $biner, string $alasan): void
    {
        try {
            $this->proses($biner);
            $this->fail('Seharusnya ditolak.');
        } catch (FotoChatDitolak $e) {
            $this->assertSame($alasan, $e->alasan);
        }
    }

    public function test_jpeg_besar_diperkecil_ke_sisi_maksimum_dan_tetap_jpeg(): void
    {
        $this->assertSame(1280, config('ai.input.photo_max_side_px'), 'Bawaan sisi terpanjang ~1280 px.');
        // Dimensi kecil di tes agar hemat memori suite; logika sama dengan 1280 px.
        config(['ai.input.photo_max_side_px' => 400]);
        $hasil = $this->proses(self::encode(self::gambar(800, 600), 'jpeg'));

        $this->assertSame([400, 300], [$hasil->lebar, $hasil->tinggi]);
        $this->assertSame('image/jpeg', $hasil->gambar()['media_type']);
        $this->assertSame(82, $hasil->kualitas);
        $this->assertSame('image/jpeg', getimagesizefromstring(base64_decode($hasil->base64))['mime']);
        $this->assertArrayNotHasKey('base64', $hasil->konteksLog());
    }

    public function test_png_transparan_dan_webp_menjadi_jpeg_berlatar_putih(): void
    {
        $g = imagecreatetruecolor(40, 30);
        imagealphablending($g, false);
        imagesavealpha($g, true);
        imagefill($g, 0, 0, (int) imagecolorallocatealpha($g, 0, 0, 0, 127));
        $hasil = $this->proses(self::encode($g, 'png'));

        $jpeg = imagecreatefromstring(base64_decode($hasil->base64));
        $rgb = imagecolorat($jpeg, 10, 10);
        $this->assertGreaterThan(240, ($rgb >> 16) & 0xFF, 'Area transparan menjadi putih, bukan hitam.');
        $this->assertSame('image/png', $hasil->mimeAsli);

        if ((imagetypes() & IMG_WEBP) !== 0) {
            $this->assertSame('image/webp', $this->proses(self::encode(self::gambar(50, 40), 'webp'))->mimeAsli);
        }
    }

    public function test_format_lain_dan_skrip_bertopeng_ditolak(): void
    {
        $this->assertDitolak(self::encode(self::gambar(10, 10), 'gif'), FotoChatDitolak::FORMAT);
        $this->assertDitolak('<?php echo "jpg"; ?>', FotoChatDitolak::FORMAT);
        $this->assertDitolak("\xFF\xD8\xFF\xE0<?php echo 1; ?>", FotoChatDitolak::RUSAK);

        // Header PNG palsu + skrip: ditolak (format atau rusak, tergantung deteksi libmagic), tidak pernah lolos.
        try {
            $this->proses("\x89PNG\r\n\x1A\n<?php echo 1; ?>");
            $this->fail('Seharusnya ditolak.');
        } catch (FotoChatDitolak $e) {
            $this->assertContains($e->alasan, [FotoChatDitolak::FORMAT, FotoChatDitolak::RUSAK]);
        }
        $this->assertDitolak('', FotoChatDitolak::RUSAK);
    }

    public function test_batas_byte_dan_piksel(): void
    {
        config(['ai.input.photo_max_bytes' => 100]);
        $this->assertDitolak(self::encode(self::gambar(64, 64), 'jpeg'), FotoChatDitolak::TERLALU_BESAR);

        config(['ai.input.photo_max_bytes' => 5 * 1024 * 1024, 'ai.input.photo_max_pixels' => 1000]);
        $this->assertDitolak(self::encode(self::gambar(64, 64), 'jpeg'), FotoChatDitolak::TERLALU_BESAR);
    }

    /** PNG kecil (beberapa ratus byte) dengan IHDR 60000x60000: ditolak dari header, SEBELUM decode GD. */
    public function test_bom_piksel_png_ihdr_raksasa_ditolak_sebelum_decode(): void
    {
        $chunk = fn (string $jenis, string $data): string => pack('N', strlen($data)).$jenis.$data.pack('N', crc32($jenis.$data));
        $png = "\x89PNG\r\n\x1A\n"
            .$chunk('IHDR', pack('NNCCCCC', 60000, 60000, 8, 2, 0, 0, 0))
            .$chunk('IDAT', (string) gzcompress(str_repeat("\x00", 64)))
            .$chunk('IEND', '');
        $this->assertLessThan(1024, strlen($png));
        $this->assertSame([60000, 60000], array_slice(getimagesizefromstring($png), 0, 2));
        $memoriSebelum = memory_get_peak_usage(true);

        $this->assertDitolak($png, FotoChatDitolak::TERLALU_BESAR);
        $this->assertLessThan($memoriSebelum + 8 * 1024 * 1024, memory_get_peak_usage(true), 'Tidak ada alokasi kanvas raksasa.');
    }

    public function test_memory_limit_dikembalikan_setelah_diproses_dan_kebutuhan_tak_wajar_ditolak(): void
    {
        $asli = (string) ini_get('memory_limit');

        try {
            // Batas sedikit di atas pemakaian saat ini: pengaman memori harus menaikkan SEMENTARA lalu mengembalikan.
            $sempit = (string) (memory_get_usage(true) + 32 * 1024 * 1024);
            ini_set('memory_limit', $sempit);
            $this->proses(self::encode(self::gambar(200, 150), 'jpeg'));
            $this->assertSame($sempit, ini_get('memory_limit'));

            // Kebutuhan di atas GdPemrosesGambar::MAKS_MEMORI (dimensi besar, piksel diizinkan config): ditolak.
            config(['ai.input.photo_max_pixels' => PHP_INT_MAX]);

            try {
                app(GdPemrosesGambar::class)->pastikanMemori(20000, 20000);
                $this->fail('Seharusnya ditolak.');
            } catch (BerkasDitolak) {
                $this->assertSame($sempit, ini_get('memory_limit'));
            }
        } finally {
            ini_set('memory_limit', $asli);
        }
    }

    public function test_melebihi_batas_base64_diturunkan_bertahap_lalu_ditolak(): void
    {
        config(['ai.input.photo_max_side_px' => 320, 'ai.input.photo_fallback_steps' => [[320, 60], [200, 50], [120, 40]]]);
        $asli = self::encode(self::gambar(480, 360, derau: true), 'jpeg', 95);
        $pertama = $this->proses($asli);
        $this->assertSame([320, 82], [$pertama->lebar, $pertama->kualitas]);

        // Batas sedikit di bawah hasil langkah pertama: langkah berikutnya (kualitas/dimensi lebih kecil) dipakai.
        config(['ai.openai_compat.vision_max_base64_bytes' => strlen($pertama->base64) - 1]);
        $turun = $this->proses($asli);
        $this->assertLessThan(strlen($pertama->base64), strlen($turun->base64));
        $this->assertLessThan(82, $turun->kualitas);

        config(['ai.openai_compat.vision_max_base64_bytes' => 300]);
        $this->assertDitolak($asli, FotoChatDitolak::TERLALU_BESAR);
    }
}
