<?php

namespace Database\Factories;

use App\Enums\JenisPemasok;
use App\Models\Pemasok;
use App\Models\Sppg;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pemasok>
 */
class PemasokFactory extends Factory
{
    protected $model = Pemasok::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sppg_id' => Sppg::factory(),
            'nama' => fake()->randomElement(['UD', 'CV', 'Kelompok Tani', 'Peternakan', 'Toko']).' '.fake('id_ID')->lastName(),
            'jenis' => fake()->randomElement(JenisPemasok::cases()),
            'kontak' => '0812-0000-'.fake()->numerify('####'),
            'alamat' => fake('id_ID')->streetAddress(),
            'jenis_bahan' => fake()->randomElement(['Sayur', 'Beras', 'Telur', 'Ayam', 'Ikan', 'Tahu/Tempe', 'Buah']),
            'is_active' => true,
        ];
    }
}
