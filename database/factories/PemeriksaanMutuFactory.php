<?php

namespace Database\Factories;

use App\Enums\HasilMutu;
use App\Models\JadwalProduksi;
use App\Models\PemeriksaanMutu;
use App\Models\Sppg;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PemeriksaanMutu>
 */
class PemeriksaanMutuFactory extends Factory
{
    protected $model = PemeriksaanMutu::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sppg_id' => Sppg::factory(),
            'jadwal_produksi_id' => fn (array $a) => JadwalProduksi::factory()->state(['sppg_id' => $a['sppg_id']]),
            'pemeriksa_id' => null,
            'waktu_pemeriksaan' => now(),
            'suhu_makanan_c' => fake()->randomFloat(1, 62, 80),
            'kebersihan_dapur' => true,
            'kebersihan_petugas' => true,
            'rasa_baik' => true,
            'aroma_baik' => true,
            'tampilan_baik' => true,
            'organoleptik_baik' => true,
            'catatan_organoleptik' => 'Rasa, aroma, tekstur baik',
            'sampel_disimpan' => true,
            'hasil' => HasilMutu::Layak,
            'tindakan_korektif' => null,
            'foto_path' => null,
        ];
    }

    public function layak(): static
    {
        return $this->state(['hasil' => HasilMutu::Layak]);
    }

    public function tidakLayak(): static
    {
        return $this->state([
            'hasil' => HasilMutu::TidakLayak,
            'suhu_makanan_c' => 45.0,
            'aroma_baik' => false,
            'organoleptik_baik' => false,
            'catatan_organoleptik' => 'Aroma kurang segar',
            'tindakan_korektif' => 'Masak ulang dan periksa ulang',
        ]);
    }
}
