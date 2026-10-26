<?php

namespace Database\Factories;

use App\Enums\StatusJadwalDistribusi;
use App\Models\JadwalDistribusi;
use App\Models\JadwalProduksi;
use App\Models\Sppg;
use App\Models\TitikLayanan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JadwalDistribusi>
 */
class JadwalDistribusiFactory extends Factory
{
    protected $model = JadwalDistribusi::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sppg_id' => Sppg::factory(),
            'jadwal_produksi_id' => fn (array $a) => JadwalProduksi::factory()->state(['sppg_id' => $a['sppg_id']]),
            'titik_layanan_id' => fn (array $a) => TitikLayanan::factory()->state(['sppg_id' => $a['sppg_id']]),
            'armada_id' => null,
            'pengemudi_id' => null,
            'petugas_id' => null,
            'tanggal_distribusi' => fn (array $a) => JadwalProduksi::withoutGlobalScopes()->find($a['jadwal_produksi_id'])->tanggal_produksi->toDateString(),
            'jam_berangkat' => '09:00:00',
            'jam_rencana_tiba' => '09:30:00',
            'jumlah_porsi' => fake()->numberBetween(50, 300),
            'status' => StatusJadwalDistribusi::Menunggu,
            'catatan' => null,
        ];
    }

    public function siap(): static
    {
        return $this->state(['status' => StatusJadwalDistribusi::Siap]);
    }

    public function berangkat(): static
    {
        return $this->state(['status' => StatusJadwalDistribusi::Berangkat]);
    }
}
