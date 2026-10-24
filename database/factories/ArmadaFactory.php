<?php

namespace Database\Factories;

use App\Enums\StatusArmada;
use App\Models\Armada;
use App\Models\Sppg;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Armada>
 */
class ArmadaFactory extends Factory
{
    protected $model = Armada::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sppg_id' => Sppg::factory(),
            'plat_nomor' => 'B '.fake()->unique()->numerify('####').' '.strtoupper(fake()->lexify('???')),
            'jenis' => fake()->randomElement(['Mobil Boks', 'Pickup', 'Van']),
            'kapasitas_box' => fake()->numberBetween(300, 1200),
            'status' => StatusArmada::Tersedia,
        ];
    }

    public function bertugas(): static
    {
        return $this->state(['status' => StatusArmada::Bertugas]);
    }

    public function servis(): static
    {
        return $this->state(['status' => StatusArmada::Servis]);
    }
}
