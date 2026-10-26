<?php

namespace Database\Factories;

use App\Enums\StatusJadwalProduksi;
use App\Models\JadwalProduksi;
use App\Models\Menu;
use App\Models\Sppg;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JadwalProduksi>
 */
class JadwalProduksiFactory extends Factory
{
    protected $model = JadwalProduksi::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sppg_id' => Sppg::factory(),
            'menu_id' => fn (array $a) => Menu::factory()->state(['sppg_id' => $a['sppg_id']]),
            'dibuat_oleh' => null,
            'tanggal_produksi' => now()->toDateString(),
            'jam_mulai_masak' => '03:00:00',
            'target_porsi' => fake()->numberBetween(300, 900),
            'status' => StatusJadwalProduksi::Direncanakan,
            'catatan' => null,
        ];
    }

    public function selesai(): static
    {
        return $this->state(['status' => StatusJadwalProduksi::Selesai]);
    }

    public function tanggal(string $tanggal): static
    {
        return $this->state(['tanggal_produksi' => $tanggal]);
    }
}
