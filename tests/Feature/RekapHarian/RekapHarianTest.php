<?php

namespace Tests\Feature\RekapHarian;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Enums\StatusAiDraft;
use App\Enums\StatusJadwalDistribusi;
use App\Enums\StatusJadwalProduksi;
use App\Enums\StatusKeluhan;
use App\Enums\StatusKirim;
use App\Events\RekapHarianDibuat;
use App\Models\AiDraft;
use App\Models\BahanBaku;
use App\Models\BuktiDistribusi;
use App\Models\FeedbackKeluhan;
use App\Models\JadwalDistribusi;
use App\Models\JadwalProduksi;
use App\Models\LogProduksi;
use App\Models\PemeriksaanMutu;
use App\Models\PencairanDana;
use App\Models\Pengeluaran;
use App\Models\RekapHarian;
use App\Models\Sppg;
use App\Services\Rekap\DailySummaryService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\SkenarioOperasional;
use Tests\TestCase;

class RekapHarianTest extends TestCase
{
    use RefreshDatabase, SkenarioOperasional;

    private const HARI = '2026-11-02';

    private int $sppgA;

    private int $sppgB;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::HARI.' 17:00:00', 'Asia/Jakarta'));
        $this->sppgA = $this->createSppg('SPPG A');
        $this->sppgB = $this->createSppg('SPPG B');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Data satu hari lengkap untuk SPPG: lihat ekspektasi pada test_hitung_ringkasan_harian. */
    private function isiHari(int $sppgId, bool $denganNoise = true): void
    {
        $p1 = JadwalProduksi::factory()->create(['sppg_id' => $sppgId, 'tanggal_produksi' => self::HARI, 'target_porsi' => 600]);
        $p2 = JadwalProduksi::factory()->create(['sppg_id' => $sppgId, 'tanggal_produksi' => self::HARI, 'target_porsi' => 400]);
        JadwalProduksi::factory()->create(['sppg_id' => $sppgId, 'tanggal_produksi' => self::HARI, 'target_porsi' => 500, 'status' => StatusJadwalProduksi::Dibatalkan]);
        LogProduksi::factory()->create(['sppg_id' => $sppgId, 'jadwal_produksi_id' => $p1->id, 'realisasi_porsi' => 570, 'sisa_makanan_kg' => 2.5]);

        // Batch 1: tidak layak lalu layak (terbaru menang); batch 2: tidak layak.
        PemeriksaanMutu::factory()->tidakLayak()->create(['sppg_id' => $sppgId, 'jadwal_produksi_id' => $p1->id, 'waktu_pemeriksaan' => self::HARI.' 07:00:00']);
        PemeriksaanMutu::factory()->layak()->create(['sppg_id' => $sppgId, 'jadwal_produksi_id' => $p1->id, 'waktu_pemeriksaan' => self::HARI.' 08:00:00']);
        PemeriksaanMutu::factory()->tidakLayak()->create(['sppg_id' => $sppgId, 'jadwal_produksi_id' => $p2->id, 'waktu_pemeriksaan' => self::HARI.' 08:00:00']);

        $dist = fn (StatusJadwalDistribusi $s) => JadwalDistribusi::factory()->create([
            'sppg_id' => $sppgId, 'jadwal_produksi_id' => $p1->id, 'tanggal_distribusi' => self::HARI, 'status' => $s,
        ]);
        $tepat = $dist(StatusJadwalDistribusi::Diterima);
        $telat = $dist(StatusJadwalDistribusi::Diterima);
        $dist(StatusJadwalDistribusi::Dibatalkan);
        $dist(StatusJadwalDistribusi::Siap);
        BuktiDistribusi::factory()->create(['sppg_id' => $sppgId, 'jadwal_distribusi_id' => $tepat->id]);
        BuktiDistribusi::factory()->terlambat()->create(['sppg_id' => $sppgId, 'jadwal_distribusi_id' => $telat->id]);

        BahanBaku::factory()->create(['sppg_id' => $sppgId, 'stok' => 1, 'stok_minimum' => 5, 'kedaluwarsa_terdekat' => '2026-12-30']);
        BahanBaku::factory()->create(['sppg_id' => $sppgId, 'stok' => 100, 'stok_minimum' => 5, 'kedaluwarsa_terdekat' => '2026-11-01']);
        BahanBaku::factory()->create(['sppg_id' => $sppgId, 'stok' => 100, 'stok_minimum' => 5, 'kedaluwarsa_terdekat' => '2026-11-05']);
        BahanBaku::factory()->create(['sppg_id' => $sppgId, 'stok' => 100, 'stok_minimum' => 5, 'kedaluwarsa_terdekat' => '2026-11-06']);
        BahanBaku::factory()->create(['sppg_id' => $sppgId, 'stok' => 100, 'stok_minimum' => 5, 'kedaluwarsa_terdekat' => self::HARI]);

        PencairanDana::factory()->create(['sppg_id' => $sppgId, 'periode' => '2026-11', 'nominal' => 50000000, 'tanggal_cair' => '2026-11-01']);
        if ($denganNoise) {
            PencairanDana::factory()->create(['sppg_id' => $sppgId, 'periode' => '2026-11', 'nominal' => 99000000, 'tanggal_cair' => '2026-11-05']); // belum cair
            PencairanDana::factory()->create(['sppg_id' => $sppgId, 'periode' => '2026-10', 'nominal' => 77000000, 'tanggal_cair' => '2026-10-01']);
            Pengeluaran::factory()->create(['sppg_id' => $sppgId, 'tanggal' => '2026-10-31', 'nominal' => 3000000]);
            Pengeluaran::factory()->create(['sppg_id' => $sppgId, 'tanggal' => '2026-11-03', 'nominal' => 9000000]);
        }
        Pengeluaran::factory()->create(['sppg_id' => $sppgId, 'tanggal' => '2026-11-01', 'nominal' => 5000000]);
        Pengeluaran::factory()->create(['sppg_id' => $sppgId, 'tanggal' => self::HARI, 'nominal' => 1250000]);

        FeedbackKeluhan::factory()->create(['sppg_id' => $sppgId, 'created_at' => self::HARI.' 09:00:00']);
        FeedbackKeluhan::factory()->kritis()->create(['sppg_id' => $sppgId, 'created_at' => self::HARI.' 10:00:00']);
        FeedbackKeluhan::factory()->create(['sppg_id' => $sppgId, 'created_at' => '2026-11-01 10:00:00', 'status' => StatusKeluhan::Diproses]);
        FeedbackKeluhan::factory()->selesai()->create(['sppg_id' => $sppgId, 'created_at' => self::HARI.' 11:00:00']);
        FeedbackKeluhan::factory()->create(['sppg_id' => $sppgId, 'created_at' => '2026-11-03 10:00:00']); // besok: tidak dihitung
    }

    /** @return array<string, mixed> */
    private function ekspektasi(int $sppgId): array
    {
        return [
            'tanggal' => self::HARI,
            'sppg_id' => $sppgId,
            'porsi' => ['jumlah_jadwal' => 2, 'target' => 1000, 'realisasi' => 570, 'capaian_persen' => 57.0, 'jadwal_tanpa_log' => 1],
            'mutu' => ['batch' => 2, 'layak' => 1, 'tidak_layak' => 1, 'belum_diperiksa' => 0],
            'sisa_makanan_kg' => 2.5,
            'distribusi' => ['total' => 4, 'tiba' => 2, 'terlambat' => 1, 'batal' => 1, 'belum_selesai' => 1],
            'stok' => ['menipis' => 1, 'sudah_kedaluwarsa' => 1, 'akan_kedaluwarsa' => 2],
            'keuangan' => [
                'periode' => '2026-11', 'pencairan_periode' => '50000000.00', 'pengeluaran_hari_ini' => '1250000.00',
                'pengeluaran_periode' => '6250000.00', 'sisa_anggaran' => '43750000.00', 'serapan_persen' => 12.5,
            ],
            'keluhan' => ['baru' => 3, 'kritis' => 1, 'terbuka' => 3],
        ];
    }

    public function test_hitung_ringkasan_harian_semua_bagian(): void
    {
        $this->isiHari($this->sppgA);
        $this->isiHari($this->sppgB, denganNoise: false); // data SPPG lain tidak boleh bocor

        $hasil = app(DailySummaryService::class)->hitung($this->sppgA, self::HARI);

        $this->assertEquals($this->ekspektasi($this->sppgA), $hasil);
    }

    public function test_hari_kosong_semua_nol(): void
    {
        $hasil = app(DailySummaryService::class)->hitung($this->sppgA, self::HARI);

        $this->assertSame(0, $hasil['porsi']['target']);
        $this->assertSame(0.0, $hasil['porsi']['capaian_persen']);
        $this->assertSame(0, $hasil['distribusi']['total']);
        $this->assertSame(['batch' => 0, 'layak' => 0, 'tidak_layak' => 0, 'belum_diperiksa' => 0], $hasil['mutu']);
        $this->assertSame('0.00', $hasil['keuangan']['sisa_anggaran']);
        $this->assertSame(0.0, $hasil['keuangan']['serapan_persen']);
    }

    public function test_kepala_menyusun_rekap_idempoten_dan_catatan_dipertahankan(): void
    {
        $this->isiHari($this->sppgA);
        $kepala = $this->pengguna(Role::KepalaSppg, $this->sppgA);
        $this->masukPenuh($kepala);

        $this->postJson('/api/v1/rekap-harian', ['tanggal' => self::HARI, 'catatan' => 'Hujan deras'])
            ->assertCreated()
            ->assertJsonPath('data.tanggal', self::HARI)
            ->assertJsonPath('data.sppg_id', $this->sppgA)
            ->assertJsonPath('data.catatan', 'Hujan deras')
            ->assertJsonPath('data.status_kirim', 'menunggu')
            ->assertJsonPath('data.disusun_oleh.id', $kepala->id)
            ->assertJsonPath('data.ringkasan.porsi.target', 1000)
            ->assertJsonPath('data.ringkasan.keuangan.sisa_anggaran', '43750000.00');

        // Data berubah, susun ulang: 200, tidak menggandakan, catatan lama dipertahankan.
        Pengeluaran::query()->withoutGlobalScopes()->where('sppg_id', $this->sppgA)->whereDate('tanggal', self::HARI)->update(['nominal' => 2250000]);
        $this->postJson('/api/v1/rekap-harian', ['tanggal' => self::HARI])
            ->assertOk()
            ->assertJsonPath('data.catatan', 'Hujan deras')
            ->assertJsonPath('data.ringkasan.keuangan.pengeluaran_hari_ini', '2250000.00');

        $this->assertSame(1, RekapHarian::query()->count());

        // Catatan boleh dikosongkan eksplisit.
        $this->postJson('/api/v1/rekap-harian', ['tanggal' => self::HARI, 'catatan' => null])->assertOk()->assertJsonPath('data.catatan', null);
    }

    public function test_susun_tanpa_tanggal_memakai_hari_ini_wib_dan_tidak_boleh_masa_depan(): void
    {
        $kepala = $this->pengguna(Role::KepalaSppg, $this->sppgA);
        $this->masukPenuh($kepala);

        $this->postJson('/api/v1/rekap-harian')->assertCreated()->assertJsonPath('data.tanggal', self::HARI);
        $this->postJson('/api/v1/rekap-harian', ['tanggal' => '2026-11-03'])->assertUnprocessable()->assertJsonValidationErrors('tanggal');
        $this->postJson('/api/v1/rekap-harian', ['tanggal' => '02/11/2026'])->assertUnprocessable()->assertJsonValidationErrors('tanggal');
        $this->postJson('/api/v1/rekap-harian', ['catatan' => str_repeat('a', 1001)])->assertUnprocessable()->assertJsonValidationErrors('catatan');
    }

    public function test_kirim_true_memancarkan_event(): void
    {
        Event::fake([RekapHarianDibuat::class]);
        $kepala = $this->pengguna(Role::KepalaSppg, $this->sppgA);
        $this->masukPenuh($kepala);

        $this->postJson('/api/v1/rekap-harian', ['tanggal' => self::HARI])->assertCreated();
        Event::assertNotDispatched(RekapHarianDibuat::class);

        $this->postJson('/api/v1/rekap-harian', ['tanggal' => self::HARI, 'kirim' => true])->assertOk();
        Event::assertDispatchedTimes(RekapHarianDibuat::class, 1);
    }

    public function test_hak_akses_matriks_tabel_5(): void
    {
        $rekap = RekapHarian::factory()->create(['sppg_id' => $this->sppgA]);

        // R saja: admin, ahli_gizi, akuntan
        foreach ([Role::Admin, Role::AhliGizi, Role::Akuntan] as $role) {
            $this->masukPenuh($this->pengguna($role, $this->sppgA));
            $this->getJson('/api/v1/rekap-harian')->assertOk();
            $this->getJson("/api/v1/rekap-harian/{$rekap->id}")->assertOk();
            $this->postJson('/api/v1/rekap-harian', ['tanggal' => self::HARI])->assertForbidden();
            $this->patchJson("/api/v1/rekap-harian/{$rekap->id}", ['catatan' => 'x'])->assertForbidden();
            $this->postJson("/api/v1/rekap-harian/{$rekap->id}/kirim-ulang")->assertForbidden();
        }

        // tanpa akses: staf_gudang, relawan (unit apa pun)
        foreach ([[Role::StafGudang, null], [Role::Relawan, RelawanUnit::Dapur], [Role::Relawan, RelawanUnit::Distribusi]] as [$role, $unit]) {
            $this->masukPenuh($this->pengguna($role, $this->sppgA, $unit));
            $this->getJson('/api/v1/rekap-harian')->assertForbidden();
            $this->getJson("/api/v1/rekap-harian/{$rekap->id}")->assertForbidden();
            $this->getJson('/api/v1/rekap-harian/ringkasan')->assertForbidden();
            $this->postJson('/api/v1/rekap-harian', ['tanggal' => self::HARI])->assertForbidden();
        }

        $this->assertSame(1, RekapHarian::query()->count());
    }

    public function test_kepala_cru_tanpa_hapus_dan_ubah_catatan(): void
    {
        $kepala = $this->pengguna(Role::KepalaSppg, $this->sppgA);
        $rekap = RekapHarian::factory()->create(['sppg_id' => $this->sppgA, 'catatan' => null]);
        $this->masukPenuh($kepala);

        $this->patchJson("/api/v1/rekap-harian/{$rekap->id}", [])->assertUnprocessable()->assertJsonValidationErrors('catatan');
        $this->patchJson("/api/v1/rekap-harian/{$rekap->id}", ['catatan' => 'Distribusi lancar'])->assertOk()->assertJsonPath('data.catatan', 'Distribusi lancar');
        $this->deleteJson("/api/v1/rekap-harian/{$rekap->id}")->assertStatus(405);
        $this->assertSame('Distribusi lancar', $rekap->fresh()->catatan);
    }

    public function test_kirim_ulang_mengembalikan_status_dan_memancarkan_event(): void
    {
        Event::fake([RekapHarianDibuat::class]);
        $kepala = $this->pengguna(Role::KepalaSppg, $this->sppgA);
        $rekap = RekapHarian::factory()->create(['sppg_id' => $this->sppgA, 'status_kirim' => StatusKirim::Terkirim, 'dikirim_pada' => now()]);
        $this->masukPenuh($kepala);

        $this->postJson("/api/v1/rekap-harian/{$rekap->id}/kirim-ulang")->assertOk()->assertJsonPath('data.status_kirim', 'menunggu')->assertJsonPath('data.dikirim_pada', null);
        Event::assertDispatched(RekapHarianDibuat::class, fn (RekapHarianDibuat $e) => $e->id === $rekap->id && $e->sppgId === $this->sppgA);
    }

    public function test_isolasi_sppg_dan_admin_lintas_sppg(): void
    {
        $kepalaB = $this->pengguna(Role::KepalaSppg, $this->sppgB);
        $admin = $this->pengguna(Role::Admin, $this->sppgA);
        $rekapA = RekapHarian::factory()->create(['sppg_id' => $this->sppgA]);
        RekapHarian::factory()->create(['sppg_id' => $this->sppgB, 'tanggal' => '2026-11-01']);

        $this->masukPenuh($kepalaB);
        $this->getJson("/api/v1/rekap-harian/{$rekapA->id}")->assertNotFound();
        $this->patchJson("/api/v1/rekap-harian/{$rekapA->id}", ['catatan' => 'x'])->assertNotFound();
        $this->postJson("/api/v1/rekap-harian/{$rekapA->id}/kirim-ulang")->assertNotFound();
        $this->getJson('/api/v1/rekap-harian')->assertOk()->assertJsonCount(1, 'data');
        // sppg_id di body/query diabaikan untuk non-admin: rekap milik SPPG B, bukan A.
        $this->postJson('/api/v1/rekap-harian', ['tanggal' => self::HARI, 'sppg_id' => $this->sppgA])->assertCreated()->assertJsonPath('data.sppg_id', $this->sppgB);

        $this->masukPenuh($admin);
        $this->getJson('/api/v1/rekap-harian')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson("/api/v1/rekap-harian?sppg_id={$this->sppgA}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/rekap-harian/{$rekapA->id}")->assertOk();
    }

    public function test_riwayat_urut_terbaru_filter_tanggal_dan_paginasi(): void
    {
        $kepala = $this->pengguna(Role::KepalaSppg, $this->sppgA);
        foreach (['2026-10-30', '2026-10-31', '2026-11-01', '2026-11-02'] as $tgl) {
            RekapHarian::factory()->create(['sppg_id' => $this->sppgA, 'tanggal' => $tgl]);
        }
        $this->masukPenuh($kepala);

        $this->getJson('/api/v1/rekap-harian')->assertOk()->assertJsonPath('data.0.tanggal', '2026-11-02')->assertJsonPath('meta.total', 4);
        $this->getJson('/api/v1/rekap-harian?from=2026-10-31&to=2026-11-01')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/rekap-harian?per_page=3&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.last_page', 2);
    }

    public function test_endpoint_ringkasan_dasbor(): void
    {
        $this->isiHari($this->sppgA);
        $kepala = $this->pengguna(Role::KepalaSppg, $this->sppgA);
        $akuntan = $this->pengguna(Role::Akuntan, $this->sppgA);
        $admin = $this->pengguna(Role::Admin, $this->sppgA);

        $this->masukPenuh($akuntan);
        $this->getJson('/api/v1/rekap-harian/ringkasan?tanggal='.self::HARI)->assertOk()->assertJsonPath('data.porsi.realisasi', 570)->assertJsonPath('data.distribusi.terlambat', 1);
        $this->assertSame(0, RekapHarian::query()->count(), 'ringkasan dasbor tidak menyimpan');

        $this->masukPenuh($kepala);
        $this->getJson('/api/v1/rekap-harian/ringkasan')->assertOk()->assertJsonPath('data.tanggal', self::HARI)->assertJsonPath('data.sppg_id', $this->sppgA);
        $this->getJson('/api/v1/rekap-harian/ringkasan?tanggal=2026-12-01')->assertUnprocessable()->assertJsonValidationErrors('tanggal');

        $this->masukPenuh($admin);
        $this->getJson('/api/v1/rekap-harian/ringkasan')->assertUnprocessable()->assertJsonValidationErrors('sppg_id');
        $this->getJson("/api/v1/rekap-harian/ringkasan?sppg_id={$this->sppgA}")->assertOk()->assertJsonPath('data.porsi.target', 1000);
    }

    public function test_ai_draft_dikonfirmasi(): void
    {
        $kepala = $this->pengguna(Role::KepalaSppg, $this->sppgA);
        $draf = AiDraft::factory()->create(['sppg_id' => $this->sppgA, 'user_id' => $kepala->id, 'module' => 'rekap-harian']);
        $this->masukPenuh($kepala);

        $this->postJson('/api/v1/rekap-harian', ['ai_draft_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('ai_draft_id');
        $this->assertSame(0, RekapHarian::query()->count());
        $this->postJson('/api/v1/rekap-harian', ['ai_draft_id' => $draf->uuid])->assertCreated();
        $this->assertSame(StatusAiDraft::Confirmed, $draf->fresh()->status);
    }

    public function test_command_menyusun_rekap_semua_sppg_aktif_dan_memancarkan_event(): void
    {
        Event::fake([RekapHarianDibuat::class]);
        $libur = Sppg::factory()->libur()->create();
        $this->isiHari($this->sppgA, denganNoise: false);

        $this->artisan('sigap:rekap-harian')->expectsOutput('Rekap harian disusun: 2 SPPG, gagal: 0')->assertSuccessful();

        $this->assertSame(2, RekapHarian::query()->count());
        $this->assertSame(0, RekapHarian::query()->where('sppg_id', $libur->id)->count());
        Event::assertDispatchedTimes(RekapHarianDibuat::class, 2);
        Event::assertDispatched(RekapHarianDibuat::class, function (RekapHarianDibuat $e) {
            $p = $e->toArray();

            return $e->sppgId === $this->sppgA && $p['tanggal'] === self::HARI && $p['ringkasan']['porsi']['target'] === 1000
                && is_int($p['id']) && $p['ringkasan']['sppg_id'] === $this->sppgA;
        });
    }

    public function test_command_idempoten_per_sppg_dan_tanggal(): void
    {
        Event::fake([RekapHarianDibuat::class]);

        $this->artisan('sigap:rekap-harian')->assertSuccessful();
        $this->artisan('sigap:rekap-harian')->assertSuccessful();
        $this->artisan('sigap:rekap-harian', ['--tanggal' => '2026-11-01', '--sppg' => $this->sppgA])->expectsOutput('Rekap harian disusun: 1 SPPG, gagal: 0')->assertSuccessful();

        $this->assertSame(3, RekapHarian::query()->count()); // A+B hari ini, A kemarin
        $this->assertSame(1, RekapHarian::query()->where('sppg_id', $this->sppgA)->whereDate('tanggal', self::HARI)->count());
    }

    public function test_command_menolak_tanggal_tidak_sah(): void
    {
        $this->artisan('sigap:rekap-harian', ['--tanggal' => '2026-13-45'])->assertExitCode(2);
        $this->artisan('sigap:rekap-harian', ['--sppg' => 'abc'])->assertExitCode(2);
        $this->assertSame(0, RekapHarian::query()->count());
    }

    public function test_command_terjadwal_17_wib(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'sigap:rekap-harian'));

        $this->assertNotNull($event);
        $this->assertSame('0 17 * * *', $event->expression);
        $this->assertSame('Asia/Jakarta', (string) $event->timezone);
    }
}
