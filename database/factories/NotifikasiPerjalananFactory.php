<?php

namespace Database\Factories;

use App\Enums\StatusKirim;
use App\Models\JadwalDistribusi;
use App\Models\NotifikasiPerjalanan;
use App\Models\Sppg;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotifikasiPerjalanan>
 */
class NotifikasiPerjalananFactory extends Factory
{
    protected $model = NotifikasiPerjalanan::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sppg_id' => Sppg::factory(),
            'jadwal_distribusi_id' => fn (array $a) => JadwalDistribusi::factory()->state(['sppg_id' => $a['sppg_id']]),
            'titik_layanan_id' => fn (array $a) => JadwalDistribusi::withoutGlobalScopes()->find($a['jadwal_distribusi_id'])->titik_layanan_id,
            'dipicu_oleh' => null,
            'penerima_chat_id' => 900000001,
            'dialihkan_ke_kepala' => false,
            'waktu_kirim' => now(),
            'estimasi_tiba' => now()->addMinutes(30),
            'status_kirim' => StatusKirim::Terkirim,
            'pesan' => 'Mobil SPPG berangkat, estimasi tiba 30 menit.',
            'error' => null,
        ];
    }

    public function tidakTerkirim(): static
    {
        return $this->state([
            'status_kirim' => StatusKirim::TidakTerkirim,
            'penerima_chat_id' => null,
            'dialihkan_ke_kepala' => true,
            'waktu_kirim' => null,
        ]);
    }
}
