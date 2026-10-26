<?php

namespace Database\Factories;

use App\Enums\TipeUndangan;
use App\Models\Invitation;
use App\Models\Sppg;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    protected $model = Invitation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'token_hash' => hash('sha256', Str::random(40)),
            'tipe' => TipeUndangan::Pengguna,
            'role' => 'relawan',
            'unit' => 'dapur',
            'sppg_id' => Sppg::factory(),
            'user_id' => null,
            'titik_layanan_id' => null,
            'created_by' => fn (array $a) => User::factory()->state(['sppg_id' => $a['sppg_id']]),
            'expires_at' => now()->addHours(72),
            'used_at' => null,
            'used_by' => null,
        ];
    }

    public function kedaluwarsa(): static
    {
        return $this->state(['expires_at' => now()->subHour()]);
    }

    public function terpakai(): static
    {
        return $this->state(['used_at' => now()]);
    }
}
