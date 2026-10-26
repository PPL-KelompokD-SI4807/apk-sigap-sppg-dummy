<?php

namespace Database\Factories;

use App\Enums\StatusPengajuanMenu;
use App\Models\Menu;
use App\Models\PengajuanMenu;
use App\Models\Sppg;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Pengajuan contoh (fiktif) untuk seeder dan tes. Dibuat langsung berstatus `diajukan`; status akhir
 * lewat state (factory mem-bypass jalur service, jadi hanya untuk data uji).
 *
 * @extends Factory<PengajuanMenu>
 */
class PengajuanMenuFactory extends Factory
{
    protected $model = PengajuanMenu::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sppg_id' => Sppg::factory(),
            'menu_id' => fn (array $a) => Menu::factory()->diajukan()->state(['sppg_id' => $a['sppg_id']]),
            'nomor' => 1,
            'menu_versi' => 1,
            'status' => StatusPengajuanMenu::Diajukan,
            'rincian' => ['menu' => ['nama_menu' => 'Menu Contoh', 'kelompok_sasaran' => 'siswa', 'gizi_per_porsi' => []], 'bahan' => [], 'tanggal_acuan' => now()->toDateString()],
            'biaya_per_porsi' => 6000,
            'jumlah_porsi' => 100,
            'sumber_porsi' => 'penerima_manfaat_total',
            'keterangan_asumsi' => 'Data contoh.',
            'total_biaya' => 600000,
            'catatan_pengaju' => null,
            'diajukan_oleh' => null,
            'diajukan_pada' => now(),
        ];
    }

    public function status(StatusPengajuanMenu $status, ?string $catatan = null): static
    {
        return $this->state([
            'status' => $status,
            'diputuskan_pada' => $status === StatusPengajuanMenu::Diajukan ? null : now(),
            'catatan_keputusan' => $catatan,
        ]);
    }
}
