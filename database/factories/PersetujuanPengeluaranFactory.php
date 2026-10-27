<?php

namespace Database\Factories;

use App\Enums\StatusPengajuanPengeluaran;
use App\Models\Pengeluaran;
use App\Models\PersetujuanPengeluaran;
use App\Models\Sppg;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Pengajuan persetujuan pengeluaran contoh (fiktif) untuk seeder/tes; mem-bypass jalur service.
 *
 * @extends Factory<PersetujuanPengeluaran>
 */
class PersetujuanPengeluaranFactory extends Factory
{
    protected $model = PersetujuanPengeluaran::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sppg_id' => Sppg::factory(),
            'pengeluaran_id' => fn (array $a) => Pengeluaran::factory()->state(['sppg_id' => $a['sppg_id'], 'status_persetujuan' => 'diajukan']),
            'nomor' => 1,
            'pengeluaran_versi' => 1,
            'status' => StatusPengajuanPengeluaran::Diajukan,
            'kategori' => 'energi',
            'nominal' => 750000,
            'tanggal' => now()->toDateString(),
            'ambang_persetujuan' => 0,
            'diajukan_oleh' => null,
            'diajukan_pada' => now(),
        ];
    }

    public function status(StatusPengajuanPengeluaran $status, ?string $catatan = null): static
    {
        return $this->state([
            'status' => $status,
            'diputuskan_pada' => $status === StatusPengajuanPengeluaran::Diajukan ? null : now(),
            'catatan_keputusan' => $catatan,
        ]);
    }
}
