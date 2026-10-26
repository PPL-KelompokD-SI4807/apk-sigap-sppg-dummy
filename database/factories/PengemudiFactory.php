<?php

namespace Database\Factories;

use App\Models\Pengemudi;
use App\Models\Sppg;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pengemudi>
 */
class PengemudiFactory extends Factory
{
    protected $model = Pengemudi::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sppg_id' => Sppg::factory(),
            'nama' => fake('id_ID')->name('male'),
            'no_hp' => '0812-0000-'.fake()->numerify('####'),
            'no_sim' => null,
            'is_active' => true,
        ];
    }
}
