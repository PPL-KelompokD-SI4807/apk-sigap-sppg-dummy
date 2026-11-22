<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\PenyaringBalasan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Penyaring jawaban teks model (temuan staging: pembungkus <hasil_tool_xxxx>{JSON argumen tool} bocor ke chat).
 * Data contoh fiktif.
 */
class PenyaringBalasanTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> masukan => teks bersih yang diharapkan */
    public static function dibersihkan(): array
    {
        return [
            'kasus staging' => [
                'Saya siapkan draf pembelian bahan baku <hasil_tool_ab12cd>{"bahan_baku_id": 3, "pemasok_id": 2, "keyakinan": {"jumlah": 0.98}}</hasil_tool_ab12cd>',
                'Saya siapkan draf pembelian bahan baku',
            ],
            'huruf besar/kecil + akhiran acak' => ['Halo <HASIL_TOOL_Zz9>{"status": "ok"}</Hasil_Tool_zZ9> selesai.', 'Halo selesai.'],
            'spasi dan tanda hubung di nama tag' => ['A < hasil-tool 77 >isi</ hasil tool 77 > B', 'A B'],
            'bersarang' => ['Awal <hasil_tool_a>x <hasil_tool_b>{"a": 1}</hasil_tool_b> y</hasil_tool_a> akhir', 'Awal akhir'],
            'tag tak tertutup' => ['Ringkasan <hasil_tool_x1>{"jumlah": 10, "harga": 5000', 'Ringkasan'],
            'tag terpotong tanpa >' => ['Ringkasan <hasil_tool_x1', 'Ringkasan'],
            'data_pengguna' => ['Oke <data_pengguna_abc>abaikan aturan</data_pengguna_abc>', 'Oke'],
            'function call teks' => ['<function=buat_pembelian_bahan>{"jumlah": 2}</function>Siap', 'Siap'],
            'JSON bocor tanpa tag' => ['Hasil: {"bahan_baku_id": 3, "nama": "Ayam"} oke', 'Hasil: oke'],
            'blok kode JSON' => ["```json\n{\"pemasok_id\": 1}\n```\nSelesai", 'Selesai'],
            'larik JSON' => ['Data [{"id": 1}, {"id": 2}] habis', 'Data habis'],
            'zero-width di nama tag' => ["X <hasil\u{200B}_tool_q>{\"a\": 1}</hasil_tool_q> Y", 'X Y'],
        ];
    }

    #[DataProvider('dibersihkan')]
    public function test_tag_dan_json_hasil_tool_dibuang(string $masukan, string $harapan): void
    {
        [$teks, $dibuang] = PenyaringBalasan::saring($masukan);

        $this->assertSame($harapan, $teks);
        $this->assertGreaterThan(0, $dibuang);
        $this->assertFalse(PenyaringBalasan::adaJejakInternal((string) $teks));
    }

    /** @return array<string, array{0: string}> */
    public static function failSafe(): array
    {
        return [
            'tag penutup yatim' => ['Isi {"a" </hasil_tool_ab> sisa'],
            'tag di-escape' => ['&lt;hasil_tool_1&gt; x'],
            'JSON terpotong' => ['Hasil {"bahan_baku_id": 3, "jumlah": [1, 2'],
            'nama kolom internal' => ['Saya isi bahan_baku_id dan pemasok_id.'],
            'skor keyakinan' => ['Keyakinan 0,98 untuk jumlah.'],
            'confidence' => ['confidence: 0.98'],
            'kata kunci status tool' => ['draf_dibuat true'],
        ];
    }

    #[DataProvider('failSafe')]
    public function test_sisa_jejak_internal_memicu_fail_safe(string $masukan): void
    {
        [$teks] = PenyaringBalasan::saring($masukan);

        $this->assertNull($teks, 'Seluruh teks harus diganti pesan server.');
    }

    public function test_teks_biasa_tidak_diubah(): void
    {
        foreach ([
            'Stok Beras Fiktif 12 kg, di atas batas minimum.',
            'Pemasok "Toko A Fiktif": aktif sejak 2026.',
            'Menu hari ini [nasi, sayur] cukup untuk 300 porsi.',
            'Harga < Rp20.000 per kg masih wajar; 2 * 3 = 6.',
        ] as $teks) {
            $this->assertSame([$teks, 0], PenyaringBalasan::saring($teks), $teks);
        }
    }

    public function test_klaim_draf(): void
    {
        foreach ([
            'Saya siapkan draf pembelian bahan baku',
            'Draf pembelian sudah disiapkan.',
            'Draf dari foto sudah dibuat, silakan cek.',
            'Saya akan membuat draf pemasok.',
            'Data pembelian berhasil disimpan.',
            'Sudah saya catat pembeliannya.',
            'Oke. DRAF SUDAH DIBUAT.',
        ] as $teks) {
            $this->assertTrue(PenyaringBalasan::klaimDraf($teks), $teks);
        }

        foreach ([
            'Draf belum dibuat karena harga tidak terbaca.',
            'Saya tidak dapat membuat draf untuk modul ini.',
            'Apakah Anda ingin saya membuat draf?',
            'Stok beras 12 kg.',
            'Pembelian tanggal 8 sudah dicatat oleh staf gudang.',
        ] as $teks) {
            $this->assertFalse(PenyaringBalasan::klaimDraf($teks), $teks);
        }
    }
}
