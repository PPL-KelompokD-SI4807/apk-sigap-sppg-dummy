<?php

namespace Database\Factories;

use App\Models\TelegramUpdate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramUpdate>
 */
class TelegramUpdateFactory extends Factory
{
    protected $model = TelegramUpdate::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'update_id' => fake()->unique()->numberBetween(100000, 999999999),
            'payload' => ['update_id' => 1, 'message' => ['text' => '/start']],
            'processed_at' => null,
        ];
    }
}
