<?php

namespace Tests\Feature\NotifikasiPerjalanan;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Enums\StatusAiDraft;
use App\Enums\StatusJadwalDistribusi;
use App\Enums\StatusKirim;
use App\Enums\StatusPerjalanan;
use App\Events\PerjalananDimulai;
use App\Events\PerjalananTerlambat;
use App\Events\PerjalananTiba;
use App\Models\AiDraft;
use App\Models\NotifikasiPerjalanan;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SkenarioOperasional;
use Tests\TestCase;

class NotifikasiPerjalananTest extends TestCase
{
    use RefreshDatabase, SkenarioOperasional;

    private int $sppgA;

    private int $sppgB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sppgA = $this->createSppg('SPPG A');
        $this->sppgB = $this->createSppg('SPPG B');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_kurir_memicu_notifikasi_estimasi_dari_jarak_dan_event_dimulai(): void
    {
        Event::fake([PerjalananDimulai::class]);
        $kurir = $this->pengguna(Role::Relawan, $this->sppgA, RelawanUnit::Distribusi);
        $jadwal = $this->jadwalSiap($this->sppgA, ['jarak_km' => 5, 'pic_telegram_chat_id' => 900000123, 'nama' => 'SDN Contoh 3']);
        Carbon::setTestNow(Carbon::parse('2026-11-02 08:30:00', 'Asia/Jakarta'));
        Sanctum::actingAs($kurir);

        // 5 km / 30 km/jam = 10 menit + buffer 5 = 15 menit.
        $this->postJson('/api/v1/notifikasi-perjalanan', ['jadwal_distribusi_id' => $jadwal->id, 'sppg_id' => $this->sppgB])
            ->assertCreated()
            ->assertJsonPath('data.status', 'berangkat')
            ->assertJsonPath('data.estimasi_menit', 15)
            ->assertJsonPath('data.estimasi_sumber', 'jarak')
            ->assertJsonPath('data.waktu_berangkat', '2026-11-02T08:30:00+07:00')
            ->assertJsonPath('data.estimasi_tiba', '2026-11-02T08:45:00+07:00')
            ->assertJsonPath('data.status_kirim', 'menunggu')
            ->assertJsonPath('data.dialihkan_ke_kepala', false)
            ->assertJsonPath('data.titik_layanan.pic_terhubung', true)
            ->assertJsonPath('data.terlambat', false)
            ->assertJsonPath('data.sppg_id', $this->sppgA);

        $this->assertDatabaseHas('notifikasi_perjalanans', ['sppg_id' => $this->sppgA, 'dipicu_oleh' => $kurir->id, 'penerima_chat_id' => 900000123]);

        Event::assertDispatched(PerjalananDimulai::class, function (PerjalananDimulai $e) use ($jadwal) {
            $p = $e->toArray();

            return $p['sppg_id'] === $this->sppgA
                && $p['jadwal_distribusi_id'] === $jadwal->id
                && $p['titik_layanan']['nama'] === 'SDN Contoh 3'
                && $p['pic']['telegram_chat_id'] === 900000123
                && $p['estimasi_tiba'] === '2026-11-02T08:45:00+07:00'
                && is_int($p['id']);
        });
    }

    public function test_pic_belum_terhubung_dialihkan_ke_kepala_dan_tidak_terkirim(): void
    {
        $kepala = $this->pengguna(Role::KepalaSppg, $this->sppgA);
        $jadwal = $this->jadwalSiap($this->sppgA);
        Sanctum::actingAs($kepala);

        $this->postJson('/api/v1/notifikasi-perjalanan', ['jadwal_distribusi_id' => $jadwal->id])
            ->assertCreated()
            ->assertJsonPath('data.dialihkan_ke_kepala', true)
            ->assertJsonPath('data.status_kirim', StatusKirim::TidakTerkirim->value);
    }

    public function test_estimasi_manual_dan_waktu_berangkat_dikonversi_ke_wib(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-02 08:31:00', 'Asia/Jakarta'));
        $kepala = $this->pengguna(Role::KepalaSppg, $this->sppgA);
        $jadwal = $this->jadwalSiap($this->sppgA);
        Sanctum::actingAs($kepala);

        // 01:30 UTC = 08:30 WIB
        $this->postJson('/api/v1/notifikasi-perjalanan', [
            'jadwal_distribusi_id' => $jadwal->id,
            'waktu_berangkat' => '2026-11-02T01:30:00+00:00',
            'estimasi_menit' => 20,
        ])->assertCreated()
            ->assertJsonPath('data.estimasi_sumber', 'manual')
            ->assertJsonPath('data.estimasi_tiba', '2026-11-02T08:50:00+07:00');

        $this->assertSame('2026-11-02 08:50:00', NotifikasiPerjalanan::query()->first()->getRawOriginal('estimasi_tiba'));
    }

    public function test_pratinjau_tidak_menyimpan_dan_tidak_memancarkan_event(): void
    {
        Event::fake([PerjalananDimulai::class]);
        $kepala = $this->pengguna(Role::KepalaSppg, $this->sppgA);
        $jadwal = $this->jadwalSiap($this->sppgA);
        Sanctum::actingAs($kepala);

        $this->postJson('/api/v1/notifikasi-perjalanan/pratinjau', ['jadwal_distribusi_id' => $jadwal->id])
            ->assertOk()
            ->assertJsonPath('data.penerima', 'kepala_sppg')
            ->assertJsonPath('data.estimasi_sumber', 'jarak')
            ->assertJsonStructure(['data' => ['waktu_berangkat', 'estimasi_tiba', 'estimasi_menit', 'pesan']]);

        $this->assertDatabaseCount('notifikasi_perjalanans', 0);
        Event::assertNotDispatched(PerjalananDimulai::class);
    }

    public function test_validasi_gagal_422(): void
    {
        $kepala = $this->pengguna(Role::KepalaSppg, $this->sppgA);
        $menunggu = $this->jadwalSiap($this->sppgA, [], ['status' => StatusJadwalDistribusi::Menunggu]);
        $tidakLayak = $this->jadwalSiap($this->sppgA, [], [], layak: false);
        $siap = $this->jadwalSiap($this->sppgA);
        $lain = $this->jadwalSiap($this->sppgB);
        Sanctum::actingAs($kepala);

        $this->postJson('/api/v1/notifikasi-perjalanan', [])->assertUnprocessable()->assertJsonValidationErrors('jadwal_distribusi_id');
        $this->postJson('/api/v1/notifikasi-perjalanan', ['jadwal_distribusi_id' => $menunggu->id])->assertUnprocessable()->assertJsonValidationErrors('jadwal_distribusi_id');
        $this->postJson('/api/v1/notifikasi-perjalanan', ['jadwal_distribusi_id' => $tidakLayak->id])->assertUnprocessable()->assertJsonValidationErrors('jadwal_distribusi_id');
        $this->postJson('/api/v1/notifikasi-perjalanan', ['jadwal_distribusi_id' => $lain->id])->assertUnprocessable()->assertJsonValidationErrors('jadwal_distribusi_id');
        $this->postJson('/api/v1/notifikasi-perjalanan', ['jadwal_distribusi_id' => $siap->id, 'estimasi_menit' => 0])->assertUnprocessable()->assertJsonValidationErrors('estimasi_menit');
        $this->postJson('/api/v1/notifikasi-perjalanan', ['jadwal_distribusi_id' => $siap->id, 'waktu_berangkat' => now()->addDay()->toIso8601String()])->assertUnprocessable()->assertJsonValidationErrors('waktu_berangkat');

        // Perjalanan aktif ganda untuk jadwal yang sama ditolak.
        $this->postJson('/api/v1/notifikasi-perjalanan', ['jadwal_distribusi_id' => $siap->id])->assertCreated();
        $this->postJson('/api/v1/notifikasi-perjalanan', ['jadwal_distribusi_id' => $siap->id])->assertUnprocessable()->assertJsonValidationErrors('jadwal_distribusi_id');
    }

    public function test_peran_tanpa_hak_membuat_ditolak_403(): void
    {
        $jadwal = $this->jadwalSiap($this->sppgA);
        $penolak = [
            $this->pengguna(Role::Admin, $this->sppgA),
            $this->pengguna(Role::AhliGizi, $this->sppgA),
            $this->pengguna(Role::Akuntan, $this->sppgA),
            $this->pengguna(Role::StafGudang, $this->sppgA),
            $this->pengguna(Role::Relawan, $this->sppgA, RelawanUnit::Dapur),
        ];

        foreach ($penolak as $user) {
            Sanctum::actingAs($user);
            $this->postJson('/api/v1/notifikasi-perjalanan', ['jadwal_distribusi_id' => $jadwal->id])->assertForbidden();
            $this->postJson('/api/v1/notifikasi-perjalanan/pratinjau', ['jadwal_distribusi_id' => $jadwal->id])->assertForbidden();
        }

        foreach (array_slice($penolak, 1) as $user) {
            Sanctum::actingAs($user);
            $this->getJson('/api/v1/notifikasi-perjalanan')->assertForbidden();
        }
    }

    public function test_transisi_status_valid_dan_event_tiba(): void
    {
        Event::fake([PerjalananDimulai::class, PerjalananTiba::class]);
        $kurir = $this->pengguna(Role::Relawan, $this->sppgA, RelawanUnit::Distribusi);
        $jadwal = $this->jadwalSiap($this->sppgA);
        Sanctum::actingAs($kurir);

        $id = $this->postJson('/api/v1/notifikasi-perjalanan', ['jadwal_distribusi_id' => $jadwal->id])->json('data.id');
        $url = "/api/v1/notifikasi-perjalanan/{$id}/status";

        // berangkat -> tiba langsung tidak valid
        $this->postJson($url, ['status' => 'tiba'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson($url, ['status' => 'berangkat'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson($url, ['status' => 'dalam_perjalanan'])->assertOk()->assertJsonPath('data.status', 'dalam_perjalanan');
        Event::assertNotDispatched(PerjalananTiba::class);

        $this->postJson($url, ['status' => 'tiba'])->assertOk()
            ->assertJsonPath('data.status', 'tiba')
            ->assertJsonPath('data.status_label', 'Tiba')
            ->assertJsonPath('data.terlambat', false);
        Event::assertDispatchedTimes(PerjalananTiba::class, 1);

        // status final
        $this->postJson($url, ['status' => 'dibatalkan', 'alasan' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson($url, ['status' => 'dalam_perjalanan'])->assertUnprocessable();
    }

    public function test_pembatalan_wajib_alasan(): void
    {
        $kepala = $this->pengguna(Role::KepalaSppg, $this->sppgA);
        $jadwal = $this->jadwalSiap($this->sppgA);
        Sanctum::actingAs($kepala);

        $id = $this->postJson('/api/v1/notifikasi-perjalanan', ['jadwal_distribusi_id' => $jadwal->id])->json('data.id');
        $url = "/api/v1/notifikasi-perjalanan/{$id}/status";

        $this->postJson($url, ['status' => 'dibatalkan'])->assertUnprocessable()->assertJsonValidationErrors('alasan');
        $this->postJson($url, ['status' => 'dibatalkan', 'alasan' => 'Ban bocor, diganti armada lain'])->assertOk()
            ->assertJsonPath('data.status', 'dibatalkan')
            ->assertJsonPath('data.alasan_batal', 'Ban bocor, diganti armada lain');

        // setelah batal, jadwal dapat dipicu ulang
        $this->postJson('/api/v1/notifikasi-perjalanan', ['jadwal_distribusi_id' => $jadwal->id])->assertCreated();
    }

    public function test_relawan_hanya_perjalanan_miliknya_kepala_melihat_semua(): void
    {
        $kurir1 = $this->pengguna(Role::Relawan, $this->sppgA, RelawanUnit::Distribusi);
        $kurir2 = $this->pengguna(Role::Relawan, $this->sppgA, RelawanUnit::Distribusi);
        $kepala = $this->pengguna(Role::KepalaSppg, $this->sppgA);
        $j1 = $this->jadwalSiap($this->sppgA);
        $j2 = $this->jadwalSiap($this->sppgA);
        $n1 = NotifikasiPerjalanan::factory()->create(['sppg_id' => $this->sppgA, 'jadwal_distribusi_id' => $j1->id, 'dipicu_oleh' => $kurir1->id]);
        $n2 = NotifikasiPerjalanan::factory()->create(['sppg_id' => $this->sppgA, 'jadwal_distribusi_id' => $j2->id, 'dipicu_oleh' => $kurir2->id]);

        Sanctum::actingAs($kurir1);
        $this->getJson('/api/v1/notifikasi-perjalanan')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $n1->id);
        $this->getJson("/api/v1/notifikasi-perjalanan/{$n1->id}")->assertOk();
        $this->getJson("/api/v1/notifikasi-perjalanan/{$n2->id}")->assertForbidden();
        $this->postJson("/api/v1/notifikasi-perjalanan/{$n2->id}/status", ['status' => 'dalam_perjalanan'])->assertForbidden();
        $this->assertSame(StatusPerjalanan::Berangkat, $n2->fresh()->status_perjalanan);

        Sanctum::actingAs($kepala);
        $this->getJson('/api/v1/notifikasi-perjalanan')->assertOk()->assertJsonCount(2, 'data');
        $this->postJson("/api/v1/notifikasi-perjalanan/{$n2->id}/status", ['status' => 'dalam_perjalanan'])->assertOk();
    }

    public function test_isolasi_sppg_dan_admin_hanya_baca_lintas_sppg(): void
    {
        $kepalaB = $this->pengguna(Role::KepalaSppg, $this->sppgB);
        $admin = $this->pengguna(Role::Admin, $this->sppgA);
        $jadwal = $this->jadwalSiap($this->sppgA);
        $n = NotifikasiPerjalanan::factory()->create(['sppg_id' => $this->sppgA, 'jadwal_distribusi_id' => $jadwal->id]);

        Sanctum::actingAs($kepalaB);
        $this->getJson("/api/v1/notifikasi-perjalanan/{$n->id}")->assertNotFound();
        $this->postJson("/api/v1/notifikasi-perjalanan/{$n->id}/status", ['status' => 'dalam_perjalanan'])->assertNotFound();
        $this->getJson('/api/v1/notifikasi-perjalanan')->assertOk()->assertJsonCount(0, 'data');

        Sanctum::actingAs($admin);
        $this->getJson("/api/v1/notifikasi-perjalanan/{$n->id}")->assertOk()->assertJsonPath('data.sppg_id', $this->sppgA);
        $this->getJson('/api/v1/notifikasi-perjalanan')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/notifikasi-perjalanan/{$n->id}/status", ['status' => 'dalam_perjalanan'])->assertForbidden();
    }

    public function test_daftar_per_hari_filter_dan_paginasi(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-03 10:00:00', 'Asia/Jakarta'));
        $kepala = $this->pengguna(Role::KepalaSppg, $this->sppgA);
        $titik = $this->jadwalSiap($this->sppgA)->titik_layanan_id;

        foreach (range(1, 3) as $i) {
            $j = $this->jadwalSiap($this->sppgA);
            NotifikasiPerjalanan::factory()->create(['sppg_id' => $this->sppgA, 'jadwal_distribusi_id' => $j->id, 'waktu_berangkat' => '2026-11-03 07:0'.$i.':00', 'estimasi_tiba' => '2026-11-03 07:3'.$i.':00']);
        }
        $kemarin = $this->jadwalSiap($this->sppgA);
        NotifikasiPerjalanan::factory()->tiba()->create(['sppg_id' => $this->sppgA, 'jadwal_distribusi_id' => $kemarin->id, 'titik_layanan_id' => $titik, 'waktu_berangkat' => '2026-11-02 07:00:00', 'estimasi_tiba' => '2026-11-02 07:30:00']);
        Sanctum::actingAs($kepala);

        $this->getJson('/api/v1/notifikasi-perjalanan')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/notifikasi-perjalanan?tanggal=2026-11-02')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'tiba');
        $this->getJson('/api/v1/notifikasi-perjalanan?tanggal=2026-11-03&per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.last_page', 2);
        $this->getJson('/api/v1/notifikasi-perjalanan?tanggal=2026-11-02&status=berangkat')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/notifikasi-perjalanan?tanggal=02-11-2026')->assertUnprocessable()->assertJsonValidationErrors('tanggal');
        $this->getJson('/api/v1/notifikasi-perjalanan?status=melayang')->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_status_terakhir(): void
    {
        $kepala = $this->pengguna(Role::KepalaSppg, $this->sppgA);
        $jadwal = $this->jadwalSiap($this->sppgA);
        NotifikasiPerjalanan::factory()->tiba()->create(['sppg_id' => $this->sppgA, 'jadwal_distribusi_id' => $jadwal->id, 'waktu_berangkat' => now()->subHours(3)]);
        $terbaru = NotifikasiPerjalanan::factory()->dalamPerjalanan()->create(['sppg_id' => $this->sppgA, 'jadwal_distribusi_id' => $jadwal->id, 'waktu_berangkat' => now()->subHour()]);
        Sanctum::actingAs($kepala);

        $this->getJson("/api/v1/notifikasi-perjalanan/terakhir?jadwal_distribusi_id={$jadwal->id}")
            ->assertOk()->assertJsonPath('data.id', $terbaru->id)->assertJsonPath('data.status', 'dalam_perjalanan');
        $this->getJson('/api/v1/notifikasi-perjalanan/terakhir?jadwal_distribusi_id=999999')->assertOk()->assertJsonPath('data', null);
    }

    public function test_ai_draft_dikonfirmasi_setelah_store_sukses(): void
    {
        $kurir = $this->pengguna(Role::Relawan, $this->sppgA, RelawanUnit::Distribusi);
        $jadwal = $this->jadwalSiap($this->sppgA);
        $draf = AiDraft::factory()->create(['sppg_id' => $this->sppgA, 'user_id' => $kurir->id, 'module' => 'notifikasi-perjalanan']);
        Sanctum::actingAs($kurir);

        $this->postJson('/api/v1/notifikasi-perjalanan', ['jadwal_distribusi_id' => $jadwal->id, 'ai_draft_id' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors('ai_draft_id');

        $this->postJson('/api/v1/notifikasi-perjalanan', ['jadwal_distribusi_id' => $jadwal->id, 'ai_draft_id' => $draf->uuid])->assertCreated();
        $this->assertSame(StatusAiDraft::Confirmed, $draf->fresh()->status);
    }

    public function test_command_deteksi_keterlambatan_sekali_per_perjalanan(): void
    {
        Event::fake([PerjalananTerlambat::class]);
        $lewat = NotifikasiPerjalanan::factory()->estimasiLewat()->create(['sppg_id' => $this->sppgA]);
        $lewatDalamPerjalanan = NotifikasiPerjalanan::factory()->estimasiLewat()->dalamPerjalanan()->create(['sppg_id' => $this->sppgB]);
        NotifikasiPerjalanan::factory()->estimasiLewat()->tiba()->create(['sppg_id' => $this->sppgA]);
        NotifikasiPerjalanan::factory()->create(['sppg_id' => $this->sppgA]); // estimasi +30 menit
        NotifikasiPerjalanan::factory()->estimasiLewat()->create(['sppg_id' => $this->sppgA, 'status_perjalanan' => StatusPerjalanan::Dibatalkan]);

        $this->artisan('sigap:cek-keterlambatan')->expectsOutput('Perjalanan terlambat baru: 2')->assertSuccessful();
        Event::assertDispatchedTimes(PerjalananTerlambat::class, 2);
        Event::assertDispatched(PerjalananTerlambat::class, fn (PerjalananTerlambat $e) => $e->id === $lewat->id && $e->sppgId === $this->sppgA);
        Event::assertDispatched(PerjalananTerlambat::class, fn (PerjalananTerlambat $e) => $e->id === $lewatDalamPerjalanan->id && $e->sppgId === $this->sppgB);
        $this->assertNotNull($lewat->fresh()->terlambat_dideteksi_pada);

        // Pemindaian ulang tidak menggandakan event.
        $this->artisan('sigap:cek-keterlambatan')->expectsOutput('Perjalanan terlambat baru: 0')->assertSuccessful();
        Event::assertDispatchedTimes(PerjalananTerlambat::class, 2);
    }

    public function test_command_terjadwal_tiap_lima_menit(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'sigap:cek-keterlambatan'));

        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertSame('Asia/Jakarta', (string) $event->timezone);
    }

    public function test_perjalanan_ditandai_terlambat_pada_resource(): void
    {
        $kepala = $this->pengguna(Role::KepalaSppg, $this->sppgA);
        $n = NotifikasiPerjalanan::factory()->estimasiLewat()->create(['sppg_id' => $this->sppgA]);
        Sanctum::actingAs($kepala);

        $this->getJson("/api/v1/notifikasi-perjalanan/{$n->id}")->assertOk()->assertJsonPath('data.terlambat', true);
    }
}
