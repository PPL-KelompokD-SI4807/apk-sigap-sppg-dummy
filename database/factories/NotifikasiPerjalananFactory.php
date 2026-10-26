<?php

namespace Database\Factories;

use App\Enums\StatusKirim;
use App\Enums\StatusPerjalanan;
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
            'status_perjalanan' => StatusPerjalanan::Berangkat,
            'waktu_berangkat' => now(),
            'estimasi_menit' => 30,
            'estimasi_sumber' => 'default',
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

    public function dalamPerjalanan(): static
    {
        return $this->state(['status_perjalanan' => StatusPerjalanan::DalamPerjalanan]);
    }

    public function tiba(): static
    {
        return $this->state(fn (array $a) => [
            'status_perjalanan' => StatusPerjalanan::Tiba,
            'waktu_tiba' => $a['estimasi_tiba'],
        ]);
    }

    /** Estimasi tiba sudah lewat (belum tiba, belum ditandai terlambat). */
    public function estimasiLewat(int $menitLalu = 10): static
    {
        return $this->state([
            'waktu_berangkat' => now()->subMinutes($menitLalu + 30),
            'estimasi_tiba' => now()->subMinutes($menitLalu),
        ]);
    }
}
