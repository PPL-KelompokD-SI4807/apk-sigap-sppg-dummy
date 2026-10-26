<?php

namespace Database\Factories;

use App\Enums\JenisMutasi;
use App\Models\BahanBaku;
use App\Models\MutasiStok;
use App\Models\Sppg;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MutasiStok>
 */
class MutasiStokFactory extends Factory
{
    protected $model = MutasiStok::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sppg_id' => Sppg::factory(),
            'bahan_baku_id' => fn (array $a) => BahanBaku::factory()->state(['sppg_id' => $a['sppg_id']]),
            'jadwal_produksi_id' => null,
            'pemasok_id' => null,
            'dicatat_oleh' => null,
            'jenis' => JenisMutasi::Masuk,
            'jumlah' => fake()->randomFloat(3, 5, 100),
            'harga_satuan' => fake()->randomFloat(2, 5000, 60000),
            'tanggal_mutasi' => now()->toDateString(),
            'tanggal_kedaluwarsa' => now()->addDays(fake()->numberBetween(7, 60))->toDateString(),
            'catatan' => null,
        ];
    }

    public function masuk(): static
    {
        return $this->state(['jenis' => JenisMutasi::Masuk]);
    }

    public function keluar(): static
    {
        return $this->state(['jenis' => JenisMutasi::Keluar, 'tanggal_kedaluwarsa' => null, 'harga_satuan' => null]);
    }

    public function penyesuaian(float $selisih = -2): static
    {
        return $this->state(['jenis' => JenisMutasi::Penyesuaian, 'jumlah' => $selisih, 'tanggal_kedaluwarsa' => null, 'harga_satuan' => null, 'catatan' => 'Penyesuaian stok opname']);
    }
}
