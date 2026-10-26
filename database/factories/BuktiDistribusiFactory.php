<?php

namespace Database\Factories;

use App\Models\BuktiDistribusi;
use App\Models\JadwalDistribusi;
use App\Models\Sppg;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BuktiDistribusi>
 */
class BuktiDistribusiFactory extends Factory
{
    protected $model = BuktiDistribusi::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sppg_id' => Sppg::factory(),
            'jadwal_distribusi_id' => fn (array $a) => JadwalDistribusi::factory()->state(['sppg_id' => $a['sppg_id']]),
            'dicatat_oleh' => null,
            'waktu_berangkat' => now()->subMinutes(30),
            'waktu_tiba' => now(),
            'waktu_ambil_foto' => now(),
            'porsi_diterima' => fn (array $a) => JadwalDistribusi::withoutGlobalScopes()->find($a['jadwal_distribusi_id'])->jumlah_porsi,
            'nama_pic' => fake('id_ID')->name(),
            'no_berita_acara' => 'BA-'.fake()->unique()->numerify('######'),
            'berita_acara_path' => null,
            'foto_path' => null,
            'latitude' => fake()->randomFloat(7, -7.0, -6.0),
            'longitude' => fake()->randomFloat(7, 106.0, 107.5),
            'catatan_selisih' => null,
            'terlambat' => false,
            'menit_keterlambatan' => null,
        ];
    }

    public function terlambat(int $menit = 75): static
    {
        return $this->state(['terlambat' => true, 'menit_keterlambatan' => $menit]);
    }
}
