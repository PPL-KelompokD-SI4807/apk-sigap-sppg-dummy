<?php

namespace Database\Factories;

use App\Enums\HasilAi;
use App\Enums\JenisInputAi;
use App\Models\AiInteraction;
use App\Models\Sppg;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiInteraction>
 */
class AiInteractionFactory extends Factory
{
    protected $model = AiInteraction::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sppg_id' => Sppg::factory(),
            'user_id' => fn (array $a) => User::factory()->state(['sppg_id' => $a['sppg_id']]),
            'jenis_input' => JenisInputAi::Teks,
            'tool_name' => 'buat_pengeluaran_operasional',
            'argumen' => ['tanggal' => now()->toDateString(), 'nominal' => 250000],
            'model' => 'model-fiktif',
            'latensi_ms' => fake()->numberBetween(800, 6000),
            'hasil' => HasilAi::Sukses,
            'error' => null,
        ];
    }
}
