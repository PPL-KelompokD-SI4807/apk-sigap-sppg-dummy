<?php

namespace Database\Factories;

use App\Enums\JenisTitikLayanan;
use App\Models\Sppg;
use App\Models\TitikLayanan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TitikLayanan>
 */
class TitikLayananFactory extends Factory
{
    protected $model = TitikLayanan::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sppg_id' => Sppg::factory(),
            'nama' => fake()->randomElement(['SDN', 'SMPN', 'PAUD Tunas', 'TK Pertiwi', 'Posyandu Melati', 'Posyandu Kenanga']).' '.fake()->numberBetween(1, 30).' '.fake('id_ID')->citySuffix(),
            'jenis' => fake()->randomElement(JenisTitikLayanan::cases()),
            'alamat' => fake('id_ID')->streetAddress(),
            'jarak_km' => fake()->randomFloat(2, 0.5, 12),
            'jumlah_sasaran' => fake()->numberBetween(40, 400),
            'pic_nama' => fake('id_ID')->name(),
            'pic_telepon' => '0812-0000-'.fake()->numerify('####'),
            'pic_telegram_chat_id' => null,
            'is_active' => true,
        ];
    }

    public function picTerhubung(): static
    {
        return $this->state(fn () => ['pic_telegram_chat_id' => fake()->unique()->numberBetween(900000001, 900999999)]);
    }
}
