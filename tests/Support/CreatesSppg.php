<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

trait CreatesSppg
{
    protected function createSppg(string $nama = 'SPPG Contoh'): int
    {
        static $seq = 0;
        $seq++;

        return (int) DB::table('sppgs')->insertGetId([
            'kode' => 'SPPG-T'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT).'-'.substr(md5($nama.microtime()), 0, 6),
            'nama' => $nama,
            'alamat' => 'Jl. Fiktif No. '.$seq,
            'kapasitas_porsi_harian' => 3000,
            'status_operasional' => 'aktif',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
