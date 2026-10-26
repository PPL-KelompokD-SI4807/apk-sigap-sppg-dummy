<?php

namespace Database\Factories;

use App\Models\BahanBaku;
use App\Models\Menu;
use App\Models\MenuBahan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MenuBahan>
 */
class MenuBahanFactory extends Factory
{
    protected $model = MenuBahan::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'menu_id' => Menu::factory(),
            'bahan_baku_id' => fn (array $a) => BahanBaku::factory()->state(['sppg_id' => Menu::withoutGlobalScopes()->find($a['menu_id'])->sppg_id]),
            'gram_per_porsi' => fake()->randomFloat(2, 10, 150),
        ];
    }
}
