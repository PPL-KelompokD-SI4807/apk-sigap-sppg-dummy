<?php

namespace Database\Factories;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Data fiktif. Default: Kepala SPPG tanpa sppg_id (isi lewat ->state(['sppg_id' => ...])
 * atau ->forSppg($id)).
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        $faker = fake('id_ID');

        return [
            'sppg_id' => null,
            'telegram_id' => $faker->unique()->numberBetween(100_000_000, 7_999_999_999),
            'telegram_username' => $faker->optional(0.7)->userName(),
            'name' => $faker->name(),
            'role' => Role::KepalaSppg,
            'unit' => null,
            'phone' => $faker->optional(0.5)->numerify('08##########'),
            'is_active' => true,
            'last_login_at' => null,
        ];
    }

    public function role(Role $role, ?RelawanUnit $unit = null): static
    {
        return $this->state(fn () => [
            'role' => $role,
            'unit' => $role === Role::Relawan ? ($unit ?? RelawanUnit::Dapur) : null,
        ]);
    }

    public function admin(): static
    {
        return $this->role(Role::Admin)->state(['sppg_id' => null]);
    }

    public function relawan(RelawanUnit $unit = RelawanUnit::Dapur): static
    {
        return $this->role(Role::Relawan, $unit);
    }

    public function forSppg(int $sppgId): static
    {
        return $this->state(['sppg_id' => $sppgId]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function withoutTelegram(): static
    {
        return $this->state(['telegram_id' => null, 'telegram_username' => null]);
    }
}
