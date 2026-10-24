<?php

namespace Database\Factories;

use App\Enums\KelompokSasaran;
use App\Models\PenerimaManfaat;
use App\Models\Sppg;
use App\Models\TitikLayanan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PenerimaManfaat>
 */
class PenerimaManfaatFactory extends Factory
{
    protected $model = PenerimaManfaat::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sppg_id' => Sppg::factory(),
            'titik_layanan_id' => fn (array $a) => TitikLayanan::factory()->state(['sppg_id' => $a['sppg_id']]),
            'nama' => fake('id_ID')->firstName().' '.strtoupper(fake()->randomLetter()).'.',
            'kelompok_sasaran' => fake()->randomElement(KelompokSasaran::cases()),
            'kelas_keterangan' => fake()->randomElement(['Kelas 1', 'Kelas 3', 'Kelas 5', 'Balita 2 th', 'Trimester 2']),
            'catatan_alergi' => fake()->optional(0.2)->randomElement(['Alergi kacang', 'Alergi telur', 'Alergi seafood', 'Intoleransi laktosa']),
            'is_active' => true,
        ];
    }
}
