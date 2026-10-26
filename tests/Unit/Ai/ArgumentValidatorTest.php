<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\Tools\ArgumentValidator;
use PHPUnit\Framework\TestCase;

class ArgumentValidatorTest extends TestCase
{
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'nama' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 10],
                'jenis' => ['type' => 'string', 'enum' => ['sekolah', 'paud']],
                'jumlah' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                'jarak' => ['type' => 'number', 'minimum' => 0],
                'aktif' => ['type' => 'boolean'],
                'tanggal' => ['type' => 'string', 'format' => 'date'],
                'jam' => ['type' => 'string', 'format' => 'time'],
                'catatan' => ['type' => ['string', 'null']],
                'items' => ['type' => 'array', 'maxItems' => 2, 'items' => ['type' => 'object', 'properties' => ['x' => ['type' => 'integer']], 'required' => ['x']]],
                'keyakinan' => ['type' => 'object', 'additionalProperties' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1]],
            ],
            'required' => ['nama'],
            'additionalProperties' => false,
        ];
    }

    private function errors(array $args): array
    {
        return (new ArgumentValidator)->validate($this->schema(), $args);
    }

    public function test_argumen_sah(): void
    {
        $this->assertSame([], $this->errors([
            'nama' => 'SD 1', 'jenis' => 'paud', 'jumlah' => 5, 'jarak' => 3.5, 'aktif' => true,
            'tanggal' => '2026-11-02', 'jam' => '03:30', 'catatan' => null, 'items' => [['x' => 1]], 'keyakinan' => ['nama' => 0.9],
        ]));
        $this->assertSame([], $this->errors(['nama' => 'a', 'jumlah' => 5.0]));
    }

    public function test_argumen_tidak_sah_ditolak(): void
    {
        $cases = [
            [[], 'wajib'],
            [['nama' => 'x', 'tambahan' => 1], 'tidak dikenal'],
            [['nama' => str_repeat('a', 11)], 'panjang'],
            [['nama' => ''], 'panjang'],
            [['nama' => 'x', 'jenis' => 'pasar'], 'tidak diizinkan'],
            [['nama' => 'x', 'jumlah' => '5'], 'tipe'],
            [['nama' => 'x', 'jumlah' => 5.5], 'tipe'],
            [['nama' => 'x', 'jumlah' => 101], 'maksimal'],
            [['nama' => 'x', 'jarak' => -1], 'minimal'],
            [['nama' => 'x', 'aktif' => 'ya'], 'tipe'],
            [['nama' => 'x', 'tanggal' => '2026-02-30'], 'tanggal'],
            [['nama' => 'x', 'jam' => '25:00'], 'jam'],
            [['nama' => null], 'null'],
            [['nama' => "a\x00b"], 'karakter'],
            [['nama' => 'x', 'items' => [['x' => 1], ['x' => 2], ['x' => 3]]], 'jumlah item'],
            [['nama' => 'x', 'items' => [['y' => 1]]], 'wajib'],
            [['nama' => 'x', 'keyakinan' => ['nama' => 2]], 'maksimal'],
            [['nama' => ['bukan' => 'string']], 'tipe'],
        ];

        foreach ($cases as [$args, $expected]) {
            $errors = $this->errors($args);
            $this->assertNotSame([], $errors, json_encode($args));
            $this->assertStringContainsString($expected, implode(' ', $errors), json_encode($args));
        }
    }

    public function test_struktur_terlalu_dalam_ditolak(): void
    {
        $schema = ['type' => 'object', 'additionalProperties' => true];
        $deep = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => 1]]]]]];

        $this->assertStringContainsString('terlalu dalam', implode(' ', (new ArgumentValidator)->validate($schema, $deep)));
        $this->assertSame([], (new ArgumentValidator)->validate($schema, ['a' => ['b' => 1]]));
    }

    public function test_karakter_unicode_tak_terlihat_ditolak(): void
    {
        foreach ([
            "SD\u{200B}1",      // zero-width space
            "SD\u{200D}1",      // zero-width joiner
            "SD\u{FEFF}",       // BOM
            "\u{202E}1 DS",     // right-to-left override
            "SD\u{2066}1",      // isolate bidi
            "SD\u{00AD}1",      // soft hyphen
            "SD\u{E0041}",      // tag "A" (penyelundupan teks ASCII)
            "SD\u{2028}1",      // pemisah baris Unicode
            "SD\u{3164}",       // pengisi Hangul
        ] as $nama) {
            $this->assertNotSame([], $this->errors(['nama' => $nama]), bin2hex($nama));
        }

        // Teks Indonesia biasa (huruf beraksen, emoji sederhana) tetap sah.
        $this->assertSame([], $this->errors(['nama' => 'Café 👍', 'catatan' => "Baris 1\nBaris\t2"]));
    }

    public function test_kata_kunci_atau_format_skema_tak_dikenal_tidak_diabaikan(): void
    {
        $validator = new ArgumentValidator;

        foreach ([
            ['type' => 'object', 'properties' => ['n' => ['type' => 'integer', 'multipleOf' => 5]]],
            ['type' => 'object', 'properties' => ['n' => ['type' => 'integer', 'exclusiveMaximum' => 5]]],
            ['type' => 'object', 'properties' => ['n' => ['type' => 'string', 'format' => 'date-time']]],
            ['type' => 'object', 'properties' => ['n' => ['type' => 'string', 'maxlength' => 3]]], // salah ketik
        ] as $schema) {
            $this->assertNotSame([], $validator->validate($schema, ['n' => 10]), json_encode($schema));
        }

        // Anotasi description/title tetap boleh.
        $this->assertSame([], $validator->validate(
            ['type' => 'object', 'title' => 'x', 'properties' => ['n' => ['type' => 'integer', 'description' => 'Jumlah.']]],
            ['n' => 10],
        ));
    }

    public function test_argumen_terlindungi_dibuang_di_semua_level(): void
    {
        [$clean, $removed] = ArgumentValidator::stripProtected(
            ['nama' => 'x', 'SPPG_ID' => 2, 'user_id' => 3, 'items' => [['created_by' => 9, 'x' => 1]]],
            ['sppg_id', 'user_id', 'created_by'],
        );

        $this->assertSame(['nama' => 'x', 'items' => [['x' => 1]]], $clean);
        $this->assertSame(['SPPG_ID', 'user_id', 'items.0.created_by'], $removed);
    }
}
