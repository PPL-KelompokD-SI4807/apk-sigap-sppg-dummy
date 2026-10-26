<?php

namespace Database\Factories;

use App\Enums\KelompokSasaran;
use App\Enums\StatusPersetujuanMenu;
use App\Models\Menu;
use App\Models\Sppg;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Menu>
 */
class MenuFactory extends Factory
{
    protected $model = Menu::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sppg_id' => Sppg::factory(),
            'nama_menu' => fake()->randomElement(['Nasi Ayam Sayur Bayam', 'Nasi Ikan Lele Tumis Kangkung', 'Nasi Telur Balado Sup Wortel', 'Nasi Tahu Tempe Orek Capcay']),
            'kelompok_sasaran' => KelompokSasaran::Siswa,
            'tanggal_sajian' => now()->toDateString(),
            'siklus' => 'Siklus '.fake()->numberBetween(1, 4),
            'energi_kkal' => fake()->randomFloat(2, 550, 700),
            'protein_g' => fake()->randomFloat(2, 18, 28),
            'lemak_g' => fake()->randomFloat(2, 15, 25),
            'karbohidrat_g' => fake()->randomFloat(2, 80, 100),
            'serat_g' => fake()->randomFloat(2, 6, 12),
            'catatan' => null,
            // Default disetujui agar fitur lain (jadwal produksi) tetap bisa memakai menu; tes persetujuan memakai state eksplisit.
            'status_persetujuan' => StatusPersetujuanMenu::Disetujui,
            'versi' => 1,
        ];
    }

    public function gizi(float $energi, float $protein, float $lemak, float $karbo, float $serat): static
    {
        return $this->state([
            'energi_kkal' => $energi,
            'protein_g' => $protein,
            'lemak_g' => $lemak,
            'karbohidrat_g' => $karbo,
            'serat_g' => $serat,
        ]);
    }

    public function draf(): static
    {
        return $this->state(['status_persetujuan' => StatusPersetujuanMenu::Draf]);
    }

    public function diajukan(): static
    {
        return $this->state(['status_persetujuan' => StatusPersetujuanMenu::Diajukan]);
    }
}
