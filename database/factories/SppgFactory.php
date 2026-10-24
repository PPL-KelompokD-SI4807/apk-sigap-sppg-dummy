<?php

namespace Database\Factories;

use App\Enums\StatusOperasional;
use App\Models\Sppg;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sppg>
 */
class SppgFactory extends Factory
{
    protected $model = Sppg::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'kode' => 'SPPG-'.fake()->unique()->numerify('####'),
            'nama' => 'SPPG '.fake('id_ID')->randomElement(['Melati', 'Kenanga', 'Mawar', 'Anggrek', 'Dahlia', 'Cempaka']).' '.fake('id_ID')->city(),
            'alamat' => fake('id_ID')->streetAddress().', '.fake('id_ID')->city(),
            'mitra_yayasan' => 'Yayasan '.fake('id_ID')->lastName().' Sejahtera',
            'telepon' => '0812-0000-'.fake()->numerify('####'),
            'kapasitas_porsi_harian' => fake()->numberBetween(1000, 3500),
            'status_operasional' => StatusOperasional::Aktif,
        ];
    }

    public function libur(): static
    {
        return $this->state(['status_operasional' => StatusOperasional::Libur]);
    }
}
