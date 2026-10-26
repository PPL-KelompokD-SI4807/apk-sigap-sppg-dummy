<?php

namespace Database\Factories;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sppg_id' => null,
            'user_id' => null,
            'event' => 'created',
            'auditable_type' => 'pengeluaran',
            'auditable_id' => fake()->numberBetween(1, 1000),
            'old_values' => null,
            'new_values' => ['nominal' => '100000.00'],
            'ip' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'sumber' => 'web',
        ];
    }
}
