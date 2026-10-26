<?php

namespace Database\Factories;

use App\Enums\KelompokSasaran;
use App\Models\Sppg;
use App\Models\StandarGizi;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StandarGizi>
 */
class StandarGiziFactory extends Factory
{
    protected $model = StandarGizi::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sppg_id' => Sppg::factory(),
            'kelompok_sasaran' => KelompokSasaran::Siswa,
            'energi_min' => 550,
            'energi_max' => 700,
            'protein_min' => 18,
            'protein_max' => 28,
            'lemak_min' => 15,
            'lemak_max' => 25,
            'karbohidrat_min' => 80,
            'karbohidrat_max' => 100,
            'serat_min' => 6,
            'serat_max' => 12,
        ];
    }
}
