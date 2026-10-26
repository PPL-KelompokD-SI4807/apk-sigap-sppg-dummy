<?php

namespace Tests\Feature\BuktiDistribusi;

use App\Enums\StatusJadwalDistribusi;
use App\Events\BuktiDistribusiBelumMasuk;
use App\Models\BuktiDistribusi;
use App\Models\JadwalDistribusi;
use App\Models\JadwalProduksi;
use App\Models\NotifikasiPerjalanan;
use App\Models\TitikLayanan;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\Support\CreatesSppg;
use Tests\TestCase;

/** sigap:cek-bukti-distribusi: bukti belum masuk jam rencana tiba + 60 menit (sekali per jadwal). */
class CekBuktiDistribusiTest extends TestCase
{
    use CreatesSppg, RefreshDatabase;

    private int $sppgA;

    private int $sppgB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sppgA = $this->createSppg('SPPG A');
        $this->sppgB = $this->createSppg('SPPG B');
        // Senin 2 Nov 2026, 11:00 WIB; jadwal default tiba 09:30 -> terlambat 90 menit.
        Carbon::setTestNow(Carbon::parse('2026-11-02 11:00:00', 'Asia/Jakarta'));
        Event::fake([BuktiDistribusiBelumMasuk::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<string, mixed> $attrs */
    private function jadwal(int $sppgId, string $status = 'berangkat', array $attrs = [], ?string $namaTitik = null): JadwalDistribusi
    {
        $produksi = JadwalProduksi::factory()->create(['sppg_id' => $sppgId, 'tanggal_produksi' => '2026-11-02']);
        $titik = TitikLayanan::factory()->create(['sppg_id' => $sppgId, 'nama' => $namaTitik ?? 'SDN Contoh 1']);

        return JadwalDistribusi::factory()->create(array_merge([
            'sppg_id' => $sppgId,
            'jadwal_produksi_id' => $produksi->id,
            'titik_layanan_id' => $titik->id,
            'tanggal_distribusi' => '2026-11-02',
            'jam_berangkat' => '09:00:00',
            'jam_rencana_tiba' => '09:30:00',
            'status' => $status,
        ], $attrs));
    }

    public function test_memicu_event_skalar_sekali_per_jadwal(): void
    {
        $a = $this->jadwal($this->sppgA, 'berangkat', [], 'SDN Harapan');
        $b = $this->jadwal($this->sppgB);

        $this->artisan('sigap:cek-bukti-distribusi')->expectsOutput('Jadwal dengan bukti belum masuk (baru): 2')->assertSuccessful();

        Event::assertDispatchedTimes(BuktiDistribusiBelumMasuk::class, 2);
        Event::assertDispatched(BuktiDistribusiBelumMasuk::class, function (BuktiDistribusiBelumMasuk $e) use ($a) {
            return $e->jadwalDistribusiId === $a->id
                && $e->sppgId === $this->sppgA
                && $e->titikLayananNama === 'SDN Harapan'
                && $e->jamRencanaTiba === '09:30'
                && $e->menitTerlambat === 90
                && $e->toArray() === [
                    'sppg_id' => $this->sppgA,
                    'jadwal_distribusi_id' => $a->id,
                    'titik_layanan' => ['id' => $a->titik_layanan_id, 'nama' => 'SDN Harapan'],
                    'jam_rencana_tiba' => '09:30',
                    'menit_terlambat' => 90,
                ];
        });
        Event::assertDispatched(BuktiDistribusiBelumMasuk::class, fn ($e) => $e->jadwalDistribusiId === $b->id && $e->sppgId === $this->sppgB);
        $this->assertNotNull($a->fresh()->bukti_belum_masuk_pada);

        // Pemindaian ulang (10 menit kemudian) tidak menggandakan event.
        Carbon::setTestNow(Carbon::now()->addMinutes(10));
        $this->artisan('sigap:cek-bukti-distribusi')->expectsOutput('Jadwal dengan bukti belum masuk (baru): 0')->assertSuccessful();
        Event::assertDispatchedTimes(BuktiDistribusiBelumMasuk::class, 2);
    }

    public function test_batas_60_menit_setelah_jam_rencana_tiba(): void
    {
        $j = $this->jadwal($this->sppgA);

        Carbon::setTestNow(Carbon::parse('2026-11-02 10:30:00', 'Asia/Jakarta')); // tepat +60 menit: belum
        $this->artisan('sigap:cek-bukti-distribusi')->assertSuccessful();
        Event::assertNotDispatched(BuktiDistribusiBelumMasuk::class);

        Carbon::setTestNow(Carbon::parse('2026-11-02 10:31:00', 'Asia/Jakarta')); // +61 menit
        $this->artisan('sigap:cek-bukti-distribusi')->assertSuccessful();
        Event::assertDispatchedTimes(BuktiDistribusiBelumMasuk::class, 1);
        Event::assertDispatched(BuktiDistribusiBelumMasuk::class, fn ($e) => $e->jadwalDistribusiId === $j->id && $e->menitTerlambat === 61);
    }

    public function test_batas_dapat_diatur_lewat_config(): void
    {
        config(['operasional.bukti_distribusi.batas_belum_masuk_menit' => 120]);
        $this->jadwal($this->sppgA);

        $this->artisan('sigap:cek-bukti-distribusi')->assertSuccessful(); // 90 menit < 120
        Event::assertNotDispatched(BuktiDistribusiBelumMasuk::class);
    }

    public function test_abaikan_jadwal_yang_sudah_ada_bukti_diterima_dibatalkan_atau_belum_berangkat(): void
    {
        $denganBukti = $this->jadwal($this->sppgA);
        BuktiDistribusi::factory()->create(['sppg_id' => $this->sppgA, 'jadwal_distribusi_id' => $denganBukti->id]);
        $this->jadwal($this->sppgA, 'diterima');
        $this->jadwal($this->sppgA, 'dibatalkan');
        $this->jadwal($this->sppgA, 'menunggu');
        $this->jadwal($this->sppgA, 'siap'); // siap tanpa perjalanan aktif: belum berangkat
        $this->jadwal($this->sppgA, 'berangkat', ['jam_rencana_tiba' => null]);
        $this->jadwal($this->sppgA, 'berangkat', ['jam_rencana_tiba' => '10:30:00']); // belum +60 menit
        $this->jadwal($this->sppgA, 'berangkat', ['tanggal_distribusi' => '2026-11-03']); // besok

        $this->artisan('sigap:cek-bukti-distribusi')->expectsOutput('Jadwal dengan bukti belum masuk (baru): 0')->assertSuccessful();
        Event::assertNotDispatched(BuktiDistribusiBelumMasuk::class);
    }

    public function test_siap_dengan_perjalanan_aktif_dihitung_dan_hari_lampau_ikut(): void
    {
        $siap = $this->jadwal($this->sppgA, 'siap');
        NotifikasiPerjalanan::factory()->dalamPerjalanan()->create(['sppg_id' => $this->sppgA, 'jadwal_distribusi_id' => $siap->id]);

        $siapBatalTrip = $this->jadwal($this->sppgA, 'siap');
        NotifikasiPerjalanan::factory()->create(['sppg_id' => $this->sppgA, 'jadwal_distribusi_id' => $siapBatalTrip->id, 'status_perjalanan' => 'dibatalkan']);

        $kemarin = $this->jadwal($this->sppgA, 'berangkat', ['tanggal_distribusi' => '2026-11-01']);

        $this->artisan('sigap:cek-bukti-distribusi')->expectsOutput('Jadwal dengan bukti belum masuk (baru): 2')->assertSuccessful();
        Event::assertDispatched(BuktiDistribusiBelumMasuk::class, fn ($e) => $e->jadwalDistribusiId === $siap->id);
        Event::assertDispatched(BuktiDistribusiBelumMasuk::class, fn ($e) => $e->jadwalDistribusiId === $kemarin->id && $e->menitTerlambat === 90 + 24 * 60);
        Event::assertNotDispatched(BuktiDistribusiBelumMasuk::class, fn ($e) => $e->jadwalDistribusiId === $siapBatalTrip->id);
    }

    public function test_penanda_atomik_mencegah_event_ganda_dari_pemindai_paralel(): void
    {
        $j = $this->jadwal($this->sppgA);
        // Pemindai lain sudah menandai di antara query kandidat dan klaim: klaim kedua gagal.
        $j->forceFill(['bukti_belum_masuk_pada' => now()])->saveQuietly();

        $this->artisan('sigap:cek-bukti-distribusi')->expectsOutput('Jadwal dengan bukti belum masuk (baru): 0')->assertSuccessful();
        Event::assertNotDispatched(BuktiDistribusiBelumMasuk::class);
        $this->assertSame(StatusJadwalDistribusi::Berangkat, $j->fresh()->status);
    }

    public function test_command_terjadwal_tiap_sepuluh_menit(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'sigap:cek-bukti-distribusi'));

        $this->assertNotNull($event);
        $this->assertSame('*/10 * * * *', $event->expression);
        $this->assertSame('Asia/Jakarta', (string) $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
    }
}
