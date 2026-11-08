<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiAgentService;
use App\Services\Ai\Drafts\GalatDraf;
use App\Services\Ai\Tools\ArgumentValidator;
use App\Services\Ai\Tools\PemisahArgumen;
use PHPUnit\Framework\TestCase;

/** Jalur kesalahan server (PR-F): pemisahan argumen tool tulis, galat per kolom, balasan server, Markdown. */
class GalatDrafTest extends TestCase
{
    /** @return array<string, mixed> */
    private function skema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'nama_menu' => ['type' => 'string', 'maxLength' => 20],
                'lemak_g' => ['type' => 'number', 'minimum' => 0],
                'tanggal_sajian' => ['type' => 'string', 'format' => 'date'],
                'bahan' => ['type' => 'array', 'maxItems' => 5, 'items' => [
                    'type' => 'object',
                    'properties' => ['nama' => ['type' => 'string'], 'jumlah' => ['type' => 'number'], 'satuan' => ['type' => 'string']],
                    'required' => ['jumlah', 'satuan'],
                    'additionalProperties' => false,
                ]],
                'keyakinan' => ['type' => 'object', 'additionalProperties' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1]],
            ],
            'required' => ['nama_menu'],
            'additionalProperties' => false,
        ];
    }

    public function test_argumen_salah_dipisah_menjadi_bagian_valid_dan_galat_kolom_tanpa_nilai(): void
    {
        $hasil = PemisahArgumen::pisahkan(new ArgumentValidator, $this->skema(), [
            'lemak_g' => '5 ml',
            'tanggal_sajian' => '2026-13-45',
            'bahan' => [
                ['nama' => 'beras', 'jumlah' => 80, 'satuan' => 'g'],
                ['nama' => 'ay'],
                ['nama' => 'garam', 'jumlah' => '1 sdt', 'satuan' => 'g'],
            ],
            'keyakinan' => ['lemak_g' => 7],
        ]);

        $this->assertNotNull($hasil);
        [$argumen, $galat] = $hasil;
        $this->assertSame(['bahan' => [['nama' => 'beras', 'jumlah' => 80, 'satuan' => 'g']]], $argumen, 'nama_menu wajib dicek Form Request, bukan di sini');
        $this->assertSame(['Harus berupa angka tanpa satuan.'], $galat['lemak_g']);
        $this->assertSame(['Tanggal harus YYYY-MM-DD.'], $galat['tanggal_sajian']);
        $this->assertCount(2, $galat['bahan']);
        $this->assertArrayNotHasKey('keyakinan', $galat);
        $this->assertStringNotContainsString('5 ml', json_encode($galat));
        $this->assertStringNotContainsString('sdt', json_encode($galat));
    }

    public function test_galat_tak_terpetakan_atau_argumen_di_luar_skema_tetap_ditolak(): void
    {
        $v = new ArgumentValidator;
        $this->assertNull(PemisahArgumen::pisahkan($v, $this->skema(), ['bahan' => [['nama' => ['a' => ['b' => ['c' => ['d' => 1]]]]]]]));
        // Argumen di luar skema (injeksi status/total) = seluruh panggilan ditolak, bukan dibuang diam-diam.
        $this->assertNull(PemisahArgumen::pisahkan($v, $this->skema(), ['lemak_g' => '5 ml', 'status' => 'disetujui']));
        $this->assertNull(PemisahArgumen::pisahkan($v, $this->skema(), ['bahan' => [['nama' => 'x', 'jumlah' => 1, 'satuan' => 'g', 'total' => 1]]]));
    }

    public function test_jalur_penyedia_menjadi_galat_kolom(): void
    {
        $this->assertSame([
            'lemak_g' => ['Format isian tidak sesuai (mis. satuan yang tidak dikenal); isi ulang.'],
            'bahan' => ['Sebagian baris tidak sesuai format; periksa kembali.'],
        ], PemisahArgumen::dariJalurPenyedia(['/lemak_g', '/bahan/0/jumlah', '/keyakinan/x']));
    }

    public function test_galat_dibersihkan_dikosongkan_dan_digabung(): void
    {
        $galat = GalatDraf::bersihkan([
            'lemak_g' => ["Harus\nangka", 'Harus angka'],
            '_pilihan' => ['x'],
            'bahan.0.bahan_baku_id' => [str_repeat('a', 500)],
            'kunci aneh' => ['x'],
            'kosong' => [''],
        ]);

        $this->assertSame(['Harus angka'], $galat['lemak_g']);
        $this->assertSame(GalatDraf::MAKS_PANJANG, mb_strlen($galat['bahan.0.bahan_baku_id'][0]));
        $this->assertSame(['lemak_g', 'bahan.0.bahan_baku_id'], array_keys($galat));

        $payload = GalatDraf::kosongkan(['lemak_g' => 5, 'nama' => 'x', 'bahan' => [['bahan_baku_id' => 1, 'gram_per_porsi' => 2]]], ['lemak_g' => ['x'], 'bahan' => ['x'], 'bahan.0.gram_per_porsi' => ['x']]);
        $this->assertSame(['nama' => 'x', 'bahan' => [['bahan_baku_id' => 1]]], $payload, 'Skalar dikosongkan; larik baris dipertahankan');

        $this->assertSame(['a' => ['server'], 'b' => ['fr']], GalatDraf::gabung(['a' => ['server']], ['a' => ['wajib diisi'], 'b' => ['fr']]));
    }

    public function test_balasan_server_hanya_label_kolom(): void
    {
        $teks = GalatDraf::pesanBalasan('Menu & Gizi', [
            'lemak_g' => ['Harus berupa angka tanpa satuan.'],
            'bahan.2.bahan_baku_id' => ['Bahan baku "kentang rahasia" tidak ditemukan.'],
            'bahan.2.gram_per_porsi' => ['x'],
        ]);

        $this->assertStringContainsString('Lemak (g), Bahan baris 3.', $teks);
        $this->assertStringContainsString('Data BELUM disimpan', $teks);
        $this->assertStringNotContainsString('kentang', $teks);
        $this->assertSame(1, substr_count($teks, 'Bahan baris 3'));

        $banyak = GalatDraf::pesanBalasan('Menu', array_fill_keys(['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'], ['x']));
        $this->assertStringContainsString('dan 2 kolom lain', $banyak);
    }

    public function test_markdown_dibuang(): void
    {
        $this->assertSame('Judul', AiAgentService::tanpaMarkdown('# Judul'));
        $this->assertSame('tebal dan miring dan kode', AiAgentService::tanpaMarkdown('**tebal** dan *miring* dan `kode`'));
        $this->assertSame('garis bawah', AiAgentService::tanpaMarkdown('__garis bawah__'));
        $this->assertSame('nama_kolom_id tetap', AiAgentService::tanpaMarkdown('nama_kolom_id tetap'));
        $this->assertSame("- daftar\n- item", AiAgentService::tanpaMarkdown("- daftar\n- item"));
        $this->assertSame('sisa  tanda', AiAgentService::tanpaMarkdown('sisa ** tanda'));
    }
}
