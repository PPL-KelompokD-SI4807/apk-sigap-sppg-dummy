<?php

namespace Database\Factories;

use App\Enums\StatusPembelianBahan;
use App\Models\BahanBaku;
use App\Models\Pemasok;
use App\Models\PembelianBahan;
use App\Models\Sppg;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Pembelian bahan contoh (fiktif). Factory mem-bypass jalur service: untuk seeder/tes. Bahan dan
 * pemasok mengikuti sppg_id yang diberikan; total dihitung dari jumlah x harga_satuan.
 *
 * @extends Factory<PembelianBahan>
 */
class PembelianBahanFactory extends Factory
{
    protected $model = PembelianBahan::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $jumlah = fake()->numberBetween(5, 50);
        $harga = fake()->numberBetween(8, 40) * 1000;

        return [
            'sppg_id' => Sppg::factory(),
            'bahan_baku_id' => fn (array $a) => BahanBaku::factory()->state(['sppg_id' => $a['sppg_id'], 'satuan' => 'kg']),
            'pemasok_id' => fn (array $a) => Pemasok::factory()->state(['sppg_id' => $a['sppg_id']]),
            'tanggal_beli' => now()->toDateString(),
            'jumlah' => $jumlah,
            'satuan' => 'kg',
            'harga_satuan' => $harga,
            'total' => $jumlah * $harga,
            'catatan' => null,
            'nota_path' => null,
            'status' => StatusPembelianBahan::Diajukan,
            'versi' => 1,
            'dicatat_oleh' => null,
        ];
    }

    public function status(StatusPembelianBahan $status): static
    {
        return $this->state(['status' => $status]);
    }
}
