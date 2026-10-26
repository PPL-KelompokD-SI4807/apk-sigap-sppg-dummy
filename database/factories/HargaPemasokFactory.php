<?php

namespace Database\Factories;

use App\Models\BahanBaku;
use App\Models\HargaPemasok;
use App\Models\Pemasok;
use App\Models\Sppg;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HargaPemasok>
 */
class HargaPemasokFactory extends Factory
{
    protected $model = HargaPemasok::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sppg_id' => Sppg::factory(),
            'pemasok_id' => fn (array $a) => Pemasok::factory()->state(['sppg_id' => $a['sppg_id']]),
            'bahan_baku_id' => fn (array $a) => BahanBaku::factory()->state(['sppg_id' => $a['sppg_id']]),
            'harga' => fake()->numberBetween(5, 120) * 1000,
            'gram_per_satuan' => null,
            'berlaku_mulai' => now()->toDateString(),
            'catatan' => null,
            'dicatat_oleh' => null,
        ];
    }
}
