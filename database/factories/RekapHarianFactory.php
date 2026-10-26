<?php

namespace Database\Factories;

use App\Enums\StatusKirim;
use App\Models\RekapHarian;
use App\Models\Sppg;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RekapHarian>
 */
class RekapHarianFactory extends Factory
{
    protected $model = RekapHarian::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sppg_id' => Sppg::factory(),
            'tanggal' => now()->toDateString(),
            'ringkasan' => ['target_porsi' => 1200, 'realisasi_porsi' => 1180, 'mutu' => 'layak', 'distribusi_selesai' => 4, 'distribusi_total' => 4, 'keluhan_terbuka' => 0, 'serapan_anggaran_persen' => 42.5, 'sisa_makanan_kg' => 3.2],
            'dikirim_pada' => null,
            'status_kirim' => StatusKirim::Menunggu,
        ];
    }
}
