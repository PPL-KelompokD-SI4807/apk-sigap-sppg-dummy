<?php

namespace Database\Factories;

use App\Enums\AksiAiDraft;
use App\Enums\StatusAiDraft;
use App\Models\AiDraft;
use App\Models\Sppg;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiDraft>
 */
class AiDraftFactory extends Factory
{
    protected $model = AiDraft::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sppg_id' => Sppg::factory(),
            'uuid' => (string) Str::uuid(),
            'user_id' => fn (array $a) => User::factory()->state(['sppg_id' => $a['sppg_id']]),
            'ai_interaction_id' => null,
            'module' => 'pengeluaran',
            'action' => AksiAiDraft::Create,
            'target_id' => null,
            'payload' => ['tanggal' => now()->toDateString(), 'kategori' => 'bahan_baku', 'uraian' => 'Belanja sayur pasar pagi', 'nominal' => 250000],
            'confidence' => ['nominal' => 0.92, 'uraian' => 0.8],
            'status' => StatusAiDraft::Pending,
            'expires_at' => now()->addMinutes(30),
            'confirmed_at' => null,
        ];
    }

    public function kedaluwarsa(): static
    {
        return $this->state(['expires_at' => now()->subMinute()]);
    }

    public function dikonfirmasi(): static
    {
        return $this->state(['status' => StatusAiDraft::Confirmed, 'confirmed_at' => now()]);
    }
}
