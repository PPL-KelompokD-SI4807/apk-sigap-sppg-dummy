<?php

namespace Tests\Feature\GrupB;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Models\AiDraft;
use App\Models\AuditLog;
use App\Models\JadwalProduksi;
use App\Models\LogProduksi;
use Laravel\Sanctum\Sanctum;

class LogProduksiTest extends GrupBTestCase
{
    private function jadwal(array $attrs = []): JadwalProduksi
    {
        return JadwalProduksi::factory()->create(array_merge(['sppg_id' => $this->sppgA->id, 'target_porsi' => 1000, 'tanggal_produksi' => now()->toDateString()], $attrs));
    }

    private function payload(JadwalProduksi $jadwal, array $override = []): array
    {
        return array_merge([
            'jadwal_produksi_id' => $jadwal->id, 'realisasi_porsi' => 980, 'sisa_makanan_kg' => 3.5,
            'waktu_mulai' => '03:10', 'jam_selesai' => '08:20', 'kendala' => 'Gas hampir habis',
        ], $override);
    }

    public function test_relawan_dapur_mencatat_log_dengan_kode_batch_dan_selisih(): void
    {
        $jadwal = $this->jadwal();
        $relawan = $this->login(Role::Relawan, null, RelawanUnit::Dapur);

        $res = $this->postJson('/api/v1/log-produksi', $this->payload($jadwal))
            ->assertCreated()
            ->assertJsonPath('data.sppg_id', $this->sppgA->id)
            ->assertJsonPath('data.dicatat_oleh', $relawan->id)
            ->assertJsonPath('data.realisasi_porsi', 980)
            ->assertJsonPath('data.target_porsi', 1000)
            ->assertJsonPath('data.selisih_porsi', -20)
            ->assertJsonPath('data.persentase_capaian', 98)
            ->assertJsonPath('data.waktu_mulai', '03:10')
            ->assertJsonPath('data.jam_selesai', '08:20')
            ->assertJsonPath('data.jadwal.id', $jadwal->id);
        $this->assertSame('BATCH-'.now()->format('Ymd').'-01', $res->json('data.kode_batch'));
        $this->assertStringContainsString('kurang 20 dari target 1000', $res->json('data.peringatan'));

        // Jadwal kedua pada tanggal yang sama: urutan batch naik.
        $jadwal2 = $this->jadwal();
        $this->postJson('/api/v1/log-produksi', $this->payload($jadwal2, ['realisasi_porsi' => 1000]))->assertCreated()
            ->assertJsonPath('data.kode_batch', 'BATCH-'.now()->format('Ymd').'-02')->assertJsonPath('data.peringatan', null)->assertJsonPath('data.selisih_porsi', 0);

        $id = $res->json('data.id');
        $this->getJson("/api/v1/log-produksi/{$id}")->assertOk();
        $this->patchJson("/api/v1/log-produksi/{$id}", ['realisasi_porsi' => 1010, 'kendala' => null])->assertOk()
            ->assertJsonPath('data.selisih_porsi', 10)->assertJsonPath('data.kode_batch', $res->json('data.kode_batch'));
    }

    public function test_satu_log_per_jadwal_dan_restore_setelah_hapus(): void
    {
        $jadwal = $this->jadwal();
        $relawan = $this->user(Role::Relawan, null, RelawanUnit::Dapur);
        $kepala = $this->user(Role::KepalaSppg);

        Sanctum::actingAs($relawan);
        $awal = $this->postJson('/api/v1/log-produksi', $this->payload($jadwal))->assertCreated();
        $this->postJson('/api/v1/log-produksi', $this->payload($jadwal))->assertUnprocessable()->assertJsonValidationErrors(['jadwal_produksi_id']);
        $this->assertSame(1, LogProduksi::withTrashed()->count());

        Sanctum::actingAs($kepala);
        $this->deleteJson('/api/v1/log-produksi/'.$awal->json('data.id'))->assertNoContent();
        $this->assertSoftDeleted('log_produksis', ['id' => $awal->json('data.id')]);

        Sanctum::actingAs($relawan);
        $baru = $this->postJson('/api/v1/log-produksi', $this->payload($jadwal, ['realisasi_porsi' => 900]))->assertCreated();
        $this->assertSame($awal->json('data.id'), $baru->json('data.id'));
        $this->assertSame($awal->json('data.kode_batch'), $baru->json('data.kode_batch'));
        $this->assertSame(900, $baru->json('data.realisasi_porsi'));
        $this->assertSame(1, LogProduksi::withTrashed()->count());
        $this->assertNull(LogProduksi::withTrashed()->first()->deleted_at);
        $events = AuditLog::query()->where('auditable_type', (new LogProduksi)->getMorphClass())->pluck('event')->all();
        $this->assertContains('deleted', $events);
        $this->assertContains('restored', $events);
    }

    public function test_validasi_422(): void
    {
        $jadwal = $this->jadwal();
        $masaDepan = $this->jadwal(['tanggal_produksi' => now()->addDays(2)->toDateString()]);
        $batal = $this->jadwal(['status' => 'dibatalkan']);
        $lain = JadwalProduksi::factory()->create(['sppg_id' => $this->sppgB->id]);
        $this->login(Role::Relawan, null, RelawanUnit::Dapur);
        $url = '/api/v1/log-produksi';

        $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors(['jadwal_produksi_id', 'realisasi_porsi']);
        $this->postJson($url, $this->payload($jadwal, ['realisasi_porsi' => -1]))->assertUnprocessable()->assertJsonValidationErrors(['realisasi_porsi']);
        $this->postJson($url, $this->payload($jadwal, ['realisasi_porsi' => 'banyak']))->assertUnprocessable()->assertJsonValidationErrors(['realisasi_porsi']);
        $this->postJson($url, $this->payload($jadwal, ['realisasi_porsi' => 3001]))->assertUnprocessable()->assertJsonValidationErrors(['realisasi_porsi']);
        $this->postJson($url, $this->payload($jadwal, ['sisa_makanan_kg' => -1]))->assertUnprocessable()->assertJsonValidationErrors(['sisa_makanan_kg']);
        $this->postJson($url, $this->payload($jadwal, ['jam_selesai' => '03:00']))->assertUnprocessable()->assertJsonValidationErrors(['jam_selesai']);
        $this->postJson($url, $this->payload($jadwal, ['waktu_mulai' => '99:00']))->assertUnprocessable()->assertJsonValidationErrors(['waktu_mulai']);
        $this->postJson($url, $this->payload($jadwal, ['lampiran_ids' => 'bukan-array']))->assertUnprocessable()->assertJsonValidationErrors(['lampiran_ids']);
        $this->postJson($url, $this->payload($jadwal, ['lampiran_ids' => [1, 2, 3, 4, 5, 6]]))->assertUnprocessable()->assertJsonValidationErrors(['lampiran_ids']);
        $this->postJson($url, $this->payload($lain))->assertUnprocessable()->assertJsonValidationErrors(['jadwal_produksi_id']);
        $this->postJson($url, $this->payload($batal))->assertUnprocessable()->assertJsonValidationErrors(['jadwal_produksi_id']);
        $this->postJson($url, $this->payload($masaDepan))->assertUnprocessable()->assertJsonValidationErrors(['jadwal_produksi_id']);
        $this->assertSame(0, LogProduksi::query()->count());

        // foto_path dari body tidak diterima (foto lewat lampiran_ids) dan tidak pernah dikirim balik.
        $id = $this->postJson($url, $this->payload($jadwal, ['foto_path' => '../../etc/passwd', 'realisasi_porsi' => 0]))->assertCreated()->assertJsonMissingPath('data.foto_path')->json('data.id');
        $this->assertNull(LogProduksi::query()->findOrFail($id)->foto_path);
    }

    public function test_hak_akses_per_peran(): void
    {
        $jadwal = $this->jadwal();
        $log = LogProduksi::factory()->create(['sppg_id' => $this->sppgA->id, 'jadwal_produksi_id' => $jadwal->id]);
        $jadwal2 = $this->jadwal();

        foreach ([Role::Admin, Role::AhliGizi, Role::Akuntan, Role::StafGudang] as $role) {
            $this->login($role);
            $this->getJson('/api/v1/log-produksi')->assertOk();
            $this->getJson("/api/v1/log-produksi/{$log->id}")->assertOk();
            $this->postJson('/api/v1/log-produksi', $this->payload($jadwal2))->assertForbidden();
            $this->patchJson("/api/v1/log-produksi/{$log->id}", ['kendala' => 'x'])->assertForbidden();
            $this->deleteJson("/api/v1/log-produksi/{$log->id}")->assertForbidden();
        }

        // Kepala SPPG: RUD tanpa C.
        $this->login(Role::KepalaSppg);
        $this->postJson('/api/v1/log-produksi', $this->payload($jadwal2))->assertForbidden();
        $this->patchJson("/api/v1/log-produksi/{$log->id}", ['kendala' => 'Direvisi kepala'])->assertOk();
        $this->deleteJson("/api/v1/log-produksi/{$log->id}")->assertNoContent();

        // Relawan unit distribusi tidak punya akses sama sekali.
        $this->login(Role::Relawan, null, RelawanUnit::Distribusi);
        $this->getJson('/api/v1/log-produksi')->assertForbidden();
        $this->postJson('/api/v1/log-produksi', $this->payload($jadwal2))->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/log-produksi')->assertUnauthorized();
    }

    public function test_relawan_dapur_hanya_mengubah_miliknya_hari_yang_sama(): void
    {
        $relawan = $this->user(Role::Relawan, null, RelawanUnit::Dapur);
        $lain = $this->user(Role::Relawan, null, RelawanUnit::Dapur);
        $milik = LogProduksi::factory()->create(['sppg_id' => $this->sppgA->id, 'jadwal_produksi_id' => $this->jadwal()->id, 'dicatat_oleh' => $relawan->id]);
        $milikLain = LogProduksi::factory()->create(['sppg_id' => $this->sppgA->id, 'jadwal_produksi_id' => $this->jadwal()->id, 'dicatat_oleh' => $lain->id]);
        $kemarin = LogProduksi::factory()->create(['sppg_id' => $this->sppgA->id, 'jadwal_produksi_id' => $this->jadwal()->id, 'dicatat_oleh' => $relawan->id]);
        $kemarin->forceFill(['created_at' => now()->subDay()])->saveQuietly();
        Sanctum::actingAs($relawan);

        $this->patchJson("/api/v1/log-produksi/{$milik->id}", ['kendala' => 'Diperbarui'])->assertOk()->assertJsonPath('data.kendala', 'Diperbarui');
        $this->patchJson("/api/v1/log-produksi/{$milikLain->id}", ['kendala' => 'x'])->assertForbidden();
        $this->patchJson("/api/v1/log-produksi/{$kemarin->id}", ['kendala' => 'x'])->assertForbidden();
        $this->deleteJson("/api/v1/log-produksi/{$milik->id}")->assertForbidden();
        $this->getJson("/api/v1/log-produksi/{$milikLain->id}")->assertOk();
    }

    public function test_lintas_sppg_dan_admin(): void
    {
        $milikB = LogProduksi::factory()->create(['sppg_id' => $this->sppgB->id]);
        LogProduksi::factory()->create(['sppg_id' => $this->sppgA->id]);

        $this->login(Role::KepalaSppg);
        $this->getJson("/api/v1/log-produksi/{$milikB->id}")->assertNotFound();
        $this->patchJson("/api/v1/log-produksi/{$milikB->id}", ['kendala' => 'x'])->assertNotFound();
        $this->deleteJson("/api/v1/log-produksi/{$milikB->id}")->assertNotFound();
        $this->getJson('/api/v1/log-produksi')->assertOk()->assertJsonCount(1, 'data');

        $this->login(Role::Admin);
        $this->getJson('/api/v1/log-produksi')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/v1/log-produksi/{$milikB->id}")->assertOk();
        $this->getJson('/api/v1/log-produksi?sppg_id='.$this->sppgB->id)->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_jadwal_id_tidak_dapat_diubah_dan_update_validasi(): void
    {
        $jadwal = $this->jadwal();
        $log = LogProduksi::factory()->create(['sppg_id' => $this->sppgA->id, 'jadwal_produksi_id' => $jadwal->id, 'waktu_mulai' => '03:00', 'jam_selesai' => '08:00']);
        $lain = $this->jadwal();
        $this->login(Role::KepalaSppg);

        $this->patchJson("/api/v1/log-produksi/{$log->id}", ['jadwal_produksi_id' => $lain->id, 'realisasi_porsi' => 500])->assertOk()->assertJsonPath('data.jadwal_produksi_id', $jadwal->id);
        $this->patchJson("/api/v1/log-produksi/{$log->id}", ['realisasi_porsi' => -3])->assertUnprocessable()->assertJsonValidationErrors(['realisasi_porsi']);
        $this->patchJson("/api/v1/log-produksi/{$log->id}", ['waktu_mulai' => '09:00'])->assertUnprocessable()->assertJsonValidationErrors(['jam_selesai']);
        $this->patchJson("/api/v1/log-produksi/{$log->id}", ['realisasi_porsi' => 999999])->assertUnprocessable()->assertJsonValidationErrors(['realisasi_porsi']);
    }

    public function test_pencarian_filter_paginasi(): void
    {
        $a = $this->jadwal(['tanggal_produksi' => '2026-10-20']);
        $b = $this->jadwal(['tanggal_produksi' => '2026-10-21']);
        LogProduksi::factory()->create(['sppg_id' => $this->sppgA->id, 'jadwal_produksi_id' => $a->id, 'kendala' => 'Gas habis', 'kode_batch' => 'BATCH-20261020-01']);
        LogProduksi::factory()->create(['sppg_id' => $this->sppgA->id, 'jadwal_produksi_id' => $b->id, 'kendala' => null, 'kode_batch' => 'BATCH-20261021-01']);
        LogProduksi::factory()->count(3)->create(['sppg_id' => $this->sppgA->id, 'kendala' => null]);
        $this->login(Role::KepalaSppg);

        $this->getJson('/api/v1/log-produksi?q=Gas')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/log-produksi?q=BATCH-20261021')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/log-produksi?tanggal=2026-10-20')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/log-produksi?from=2026-10-21&to=2026-10-21')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/log-produksi?jadwal_produksi_id='.$b->id)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/log-produksi?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 5);
    }

    public function test_draf_ai_dikonfirmasi(): void
    {
        $jadwal = $this->jadwal();
        $user = $this->user(Role::Relawan, null, RelawanUnit::Dapur);
        $draf = AiDraft::factory()->create(['sppg_id' => $this->sppgA->id, 'user_id' => $user->id, 'module' => 'log-produksi']);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/log-produksi', $this->payload($jadwal, ['ai_draft_id' => $draf->uuid]))->assertCreated();
        $this->assertSame('confirmed', $draf->fresh()->status->value);
    }
}
