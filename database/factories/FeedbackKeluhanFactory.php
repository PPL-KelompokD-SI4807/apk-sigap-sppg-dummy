<?php

namespace Database\Factories;

use App\Enums\KategoriKeluhan;
use App\Enums\PrioritasKeluhan;
use App\Enums\StatusKeluhan;
use App\Enums\SumberKeluhan;
use App\Models\FeedbackKeluhan;
use App\Models\Sppg;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeedbackKeluhan>
 */
class FeedbackKeluhanFactory extends Factory
{
    protected $model = FeedbackKeluhan::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sppg_id' => Sppg::factory(),
            'titik_layanan_id' => null,
            'jadwal_produksi_id' => null,
            'dicatat_oleh' => null,
            'ditangani_oleh' => null,
            'sumber' => fake()->randomElement(SumberKeluhan::cases()),
            'kategori' => KategoriKeluhan::Rasa,
            'prioritas' => fn (array $a) => PrioritasKeluhan::dariKategori($a['kategori']),
            'status' => StatusKeluhan::Baru,
            'deskripsi' => fake()->randomElement(['Sayur terlalu lembek', 'Porsi nasi kurang untuk kelas 6', 'Lauk terasa asin', 'Kemasan bocor saat tiba']),
            'foto_path' => null,
            'tindak_lanjut' => null,
            'dieskalasi_pada' => null,
            'selesai_pada' => null,
        ];
    }

    public function kritis(): static
    {
        return $this->state(fn () => [
            'kategori' => KategoriKeluhan::MakananBasi,
            'prioritas' => PrioritasKeluhan::Kritis,
            'deskripsi' => 'Lauk ayam berbau basi, beberapa siswa mengeluh mual',
            'dieskalasi_pada' => now(),
        ]);
    }

    public function selesai(): static
    {
        return $this->state(fn () => [
            'status' => StatusKeluhan::Selesai,
            'tindak_lanjut' => 'Sudah ditindaklanjuti oleh tim',
            'selesai_pada' => now(),
        ]);
    }
}
