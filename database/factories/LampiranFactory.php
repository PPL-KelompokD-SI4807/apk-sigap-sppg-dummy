<?php

namespace Database\Factories;

use App\Models\Lampiran;
use App\Models\Pengeluaran;
use App\Models\Sppg;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lampiran>
 */
class LampiranFactory extends Factory
{
    protected $model = Lampiran::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sppg_id' => Sppg::factory(),
            'lampiranable_type' => 'pengeluaran',
            'lampiranable_id' => fn (array $a) => Pengeluaran::factory()->state(['sppg_id' => $a['sppg_id']]),
            'uploaded_by' => null,
            'path' => 'lampiran/'.fake()->uuid().'.jpg',
            'mime' => 'image/jpeg',
            'ukuran' => fake()->numberBetween(120000, 400000),
            'sha256' => hash('sha256', fake()->uuid()),
        ];
    }
}
