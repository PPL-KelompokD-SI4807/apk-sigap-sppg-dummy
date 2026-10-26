<?php

namespace Database\Factories;

use App\Enums\KategoriPengeluaran;
use App\Enums\StatusPersetujuanPengeluaran;
use App\Models\Pengeluaran;
use App\Models\Sppg;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pengeluaran>
 */
class PengeluaranFactory extends Factory
{
    protected $model = Pengeluaran::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sppg_id' => Sppg::factory(),
            'pencairan_dana_id' => null,
            'pemasok_id' => null,
            'dicatat_oleh' => null,
            'tanggal' => now()->toDateString(),
            'kategori' => fake()->randomElement(KategoriPengeluaran::cases()),
            'uraian' => fake()->randomElement(['Belanja sayur pasar pagi', 'Beli telur 30 kg', 'Insentif relawan dapur', 'Gas elpiji 12 kg', 'Beras 100 kg']),
            'nominal' => fake()->numberBetween(150, 4000) * 1000,
            'nota_path' => null,
            // Bawaan `disetujui` agar data uji lama tetap dihitung anggaran; pakai state untuk status lain.
            'status_persetujuan' => StatusPersetujuanPengeluaran::Disetujui,
            'versi' => 1,
        ];
    }

    public function status(StatusPersetujuanPengeluaran $status): static
    {
        return $this->state(['status_persetujuan' => $status]);
    }
}
