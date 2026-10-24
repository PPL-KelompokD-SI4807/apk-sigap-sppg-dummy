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
