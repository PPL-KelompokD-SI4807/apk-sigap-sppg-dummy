<?php

namespace Database\Factories;

use App\Models\JadwalProduksi;
use App\Models\LogProduksi;
use App\Models\Sppg;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LogProduksi>
 */
class LogProduksiFactory extends Factory
{
    protected $model = LogProduksi::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sppg_id' => Sppg::factory(),
            'jadwal_produksi_id' => fn (array $a) => JadwalProduksi::factory()->state(['sppg_id' => $a['sppg_id']]),
            'dicatat_oleh' => null,
            'realisasi_porsi' => fn (array $a) => JadwalProduksi::withoutGlobalScopes()->find($a['jadwal_produksi_id'])->target_porsi - fake()->numberBetween(0, 20),
            'sisa_makanan_kg' => fake()->randomFloat(2, 0, 6),
            'jam_selesai' => '08:30:00',
            'kendala' => fake()->optional(0.3)->randomElement(['Gas hampir habis', 'Kompor kedua mati sebentar', 'Sayur terlambat datang']),
            'foto_path' => null,
        ];
    }
}
