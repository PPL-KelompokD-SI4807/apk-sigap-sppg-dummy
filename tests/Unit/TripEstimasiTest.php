<?php

namespace Tests\Unit;

use App\Models\JadwalDistribusi;
use App\Models\TitikLayanan;
use App\Services\Perjalanan\TripNotificationService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Rumus estimasi tiba (tanpa basis data): lihat docblock TripNotificationService. */
class TripEstimasiTest extends TestCase
{
    private function hitung(?float $jarak, array $jadwal = [], ?int $manual = null): array
    {
        $berangkat = Carbon::parse('2026-11-02 08:00:00', 'Asia/Jakarta');

        return (new TripNotificationService)->hitungEstimasi(
            new JadwalDistribusi($jadwal),
            new TitikLayanan(['jarak_km' => $jarak]),
            $berangkat,
            $manual,
        );
    }

    public function test_dari_jarak_dengan_buffer(): void
    {
        $h = $this->hitung(12.5); // ceil(12.5/30*60)=25 + 5
        $this->assertSame(30, $h['menit']);
        $this->assertSame('jarak', $h['sumber']);
        $this->assertSame('2026-11-02 08:30:00', $h['tiba']->format('Y-m-d H:i:s'));
        $this->assertSame('Asia/Jakarta', $h['tiba']->getTimezone()->getName());
    }

    public function test_jarak_pendek_dibatasi_durasi_minimum(): void
    {
        $this->assertSame(6, $this->hitung(0.1)['menit']); // ceil(0.2)=1 + buffer 5
        config(['operasional.perjalanan.buffer_menit' => 0]);
        $this->assertSame(5, $this->hitung(0.1)['menit']); // 1 -> min 5
    }

    public function test_manual_menimpa_semuanya(): void
    {
        $h = $this->hitung(12.5, [], 45);
        $this->assertSame(45, $h['menit']);
        $this->assertSame('manual', $h['sumber']);
    }

    public function test_fallback_selisih_jam_jadwal_lalu_default(): void
    {
        $h = $this->hitung(null, ['jam_berangkat' => '08:00:00', 'jam_rencana_tiba' => '08:40:00']);
        $this->assertSame([40, 'jadwal'], [$h['menit'], $h['sumber']]);

        $h = $this->hitung(0, ['jam_berangkat' => '09:00:00', 'jam_rencana_tiba' => '08:40:00']);
        $this->assertSame([30, 'default'], [$h['menit'], $h['sumber']]);
    }
}
