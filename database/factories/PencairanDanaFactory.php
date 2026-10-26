<?php

namespace Database\Factories;

use App\Enums\KategoriPengeluaran;
use App\Models\PencairanDana;
use App\Models\Pengeluaran;
use App\Models\Sppg;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PencairanDana>
 */
class PencairanDanaFactory extends Factory
{
    protected $model = PencairanDana::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sppg_id' => Sppg::factory(),
            'dicatat_oleh' => null,
            'periode' => now()->format('Y-m'),
            'sumber_dana' => fake()->randomElement(['BGN Tahap I', 'BGN Tahap II', 'Dana Mitra Yayasan']),
            'nominal' => fake()->randomElement([50000000, 75000000, 100000000, 150000000]),
            'tanggal_cair' => now()->startOfMonth()->toDateString(),
            'no_referensi' => 'REF-'.fake()->unique()->numerify('########'),
            'bukti_path' => null,
            'catatan' => null,
        ];
    }

    /** Pencairan yang sudah terserap persentase tertentu oleh pengeluaran. */
    public function terserap(int $persen): static
    {
        return $this->afterCreating(function (PencairanDana $pencairan) use ($persen) {
            Pengeluaran::factory()->state([
                'sppg_id' => $pencairan->sppg_id,
                'pencairan_dana_id' => $pencairan->id,
                'tanggal' => $pencairan->tanggal_cair->toDateString(),
                'kategori' => KategoriPengeluaran::BahanBaku,
                'nominal' => round((float) $pencairan->nominal * $persen / 100, 2),
            ])->create();
        });
    }

    public function terserap80(): static
    {
        return $this->terserap(80);
    }

    public function terserap100(): static
    {
        return $this->terserap(100);
    }
}
