<?php

namespace Database\Factories;

use App\Enums\KategoriBahan;
use App\Models\BahanBaku;
use App\Models\Sppg;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BahanBaku>
 */
class BahanBakuFactory extends Factory
{
    protected $model = BahanBaku::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sppg_id' => Sppg::factory(),
            'pemasok_id' => null,
            'kode' => 'BB-'.fake()->unique()->numerify('#####'),
            'nama' => fake()->randomElement(['Beras', 'Telur Ayam', 'Daging Ayam', 'Ikan Lele', 'Tahu', 'Tempe', 'Bayam', 'Wortel', 'Pisang', 'Susu UHT', 'Minyak Goreng', 'Bawang Merah']),
            'kategori' => fake()->randomElement(KategoriBahan::cases()),
            'satuan' => fake()->randomElement(['kg', 'liter', 'butir', 'ikat']),
            'stok' => fake()->randomFloat(3, 50, 500),
            'stok_minimum' => fake()->randomFloat(3, 5, 40),
            'kedaluwarsa_terdekat' => now()->addDays(fake()->numberBetween(10, 90))->toDateString(),
        ];
    }

    public function kedaluwarsa(int $hari = 2): static
    {
        return $this->state(fn () => ['kedaluwarsa_terdekat' => now()->addDays($hari)->toDateString()]);
    }

    public function sudahKedaluwarsa(): static
    {
        return $this->state(fn () => ['kedaluwarsa_terdekat' => now()->subDay()->toDateString()]);
    }

    public function stokMenipis(): static
    {
        return $this->state(['stok' => 3, 'stok_minimum' => 20]);
    }
}
