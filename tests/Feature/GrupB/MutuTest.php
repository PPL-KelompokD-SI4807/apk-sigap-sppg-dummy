<?php

namespace Tests\Feature\GrupB;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Events\MutuTidakLayak;
use App\Models\AiDraft;
use App\Models\AuditLog;
use App\Models\JadwalDistribusi;
use App\Models\JadwalProduksi;
use App\Models\PemeriksaanMutu;
use Illuminate\Support\Facades\Event;

class MutuTest extends GrupBTestCase
{
    private function jadwal(array $attrs = []): JadwalProduksi
    {
        return JadwalProduksi::factory()->create(array_merge(['sppg_id' => $this->sppgA->id, 'target_porsi' => 1000, 'tanggal_produksi' => now()->toDateString()], $attrs));
    }

    private function payload(JadwalProduksi $jadwal, array $override = []): array
    {
        return array_merge([
            'jadwal_produksi_id' => $jadwal->id, 'suhu_makanan_c' => 72.5, 'kebersihan_dapur' => true, 'kebersihan_petugas' => true,
            'organoleptik_baik' => true, 'catatan_organoleptik' => 'Rasa, aroma, tekstur baik', 'sampel_disimpan' => true, 'hasil' => 'layak',
        ], $override);
    }

    private function tidakLayak(JadwalProduksi $jadwal, array $override = []): array
    {
        return $this->payload($jadwal, array_merge([
            'suhu_makanan_c' => 45, 'organoleptik_baik' => false, 'catatan_organoleptik' => 'Aroma kurang segar',
            'hasil' => 'tidak_layak', 'tindakan_korektif' => 'Masak ulang dan periksa ulang',
        ], $override));
    }

    public function test_ahli_gizi_crud_pemeriksaan(): void
    {
        $jadwal = $this->jadwal();
        $ahli = $this->login(Role::AhliGizi);

        $id = $this->postJson('/api/v1/mutu', $this->payload($jadwal))
            ->assertCreated()
            ->assertJsonPath('data.hasil', 'layak')
            ->assertJsonPath('data.pemeriksa_id', $ahli->id)
            ->assertJsonPath('data.sppg_id', $this->sppgA->id)
            ->assertJsonPath('data.suhu_makanan_c', 72.5)
            ->assertJsonPath('data.memengaruhi_distribusi', false)
            ->assertJsonPath('data.jadwal.id', $jadwal->id)
            ->json('data.id');

        $this->assertTrue($jadwal->fresh()->isLayakDistribusi());
        $this->getJson("/api/v1/mutu/{$id}")->assertOk();
        $this->patchJson("/api/v1/mutu/{$id}", ['catatan_organoleptik' => 'Sangat baik', 'foto_path' => 'mutu/2026/11/f1.jpg'])->assertOk()->assertJsonMissingPath('data.foto_path');
        $this->assertNull(PemeriksaanMutu::query()->findOrFail($id)->foto_path);
        $this->deleteJson("/api/v1/mutu/{$id}")->assertNoContent();
        $this->assertSoftDeleted('pemeriksaan_mutus', ['id' => $id]);
        $this->assertFalse($jadwal->fresh()->isLayakDistribusi());
        $this->assertTrue(AuditLog::query()->where('auditable_type', (new PemeriksaanMutu)->getMorphClass())->where('event', 'deleted')->exists());
    }

    public function test_validasi_dan_konsistensi_checklist(): void
    {
        $jadwal = $this->jadwal();
        $depan = $this->jadwal(['tanggal_produksi' => now()->addDays(2)->toDateString()]);
        $batal = $this->jadwal(['status' => 'dibatalkan']);
        $lain = JadwalProduksi::factory()->create(['sppg_id' => $this->sppgB->id]);
        $this->login(Role::AhliGizi);
        $url = '/api/v1/mutu';

        $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors(['jadwal_produksi_id', 'kebersihan_dapur', 'kebersihan_petugas', 'rasa_baik', 'aroma_baik', 'tampilan_baik', 'organoleptik_baik', 'hasil']);
        $this->postJson($url, $this->payload($jadwal, ['hasil' => 'mungkin']))->assertUnprocessable()->assertJsonValidationErrors(['hasil']);
        $this->postJson($url, $this->payload($jadwal, ['suhu_makanan_c' => 500]))->assertUnprocessable()->assertJsonValidationErrors(['suhu_makanan_c']);
        $this->postJson($url, $this->payload($jadwal, ['kebersihan_dapur' => 'ya?']))->assertUnprocessable()->assertJsonValidationErrors(['kebersihan_dapur']);
        $this->postJson($url, $this->payload($jadwal, ['lampiran_ids' => ['x']]))->assertUnprocessable()->assertJsonValidationErrors(['lampiran_ids.0']);
        $this->postJson($url, $this->payload($jadwal, ['waktu_pemeriksaan' => now()->addDay()->toDateTimeString()]))->assertUnprocessable()->assertJsonValidationErrors(['waktu_pemeriksaan']);
        $this->postJson($url, $this->payload($lain))->assertUnprocessable()->assertJsonValidationErrors(['jadwal_produksi_id']);
        $this->postJson($url, $this->payload($batal))->assertUnprocessable()->assertJsonValidationErrors(['jadwal_produksi_id']);
        $this->postJson($url, $this->payload($depan))->assertUnprocessable()->assertJsonValidationErrors(['jadwal_produksi_id']);

        // Layak tidak boleh bila butir checklist gagal atau suhu di bawah minimum.
        $this->postJson($url, $this->payload($jadwal, ['kebersihan_petugas' => false]))->assertUnprocessable()->assertJsonValidationErrors(['hasil']);
        $res = $this->postJson($url, $this->payload($jadwal, ['suhu_makanan_c' => 55]))->assertUnprocessable()->assertJsonValidationErrors(['hasil']);
        $this->assertStringContainsString('di bawah minimum 60 C', $res->json('errors.hasil.0'));
        // Tidak layak wajib tindakan korektif; organoleptik buruk wajib catatan.
        $this->postJson($url, $this->tidakLayak($jadwal, ['tindakan_korektif' => null]))->assertUnprocessable()->assertJsonValidationErrors(['tindakan_korektif']);
        $this->postJson($url, $this->tidakLayak($jadwal, ['catatan_organoleptik' => null]))->assertUnprocessable()->assertJsonValidationErrors(['catatan_organoleptik']);
        $this->assertSame(0, PemeriksaanMutu::query()->count());
    }

    public function test_hak_akses_per_peran(): void
    {
        $jadwal = $this->jadwal();
        $m = PemeriksaanMutu::factory()->create(['sppg_id' => $this->sppgA->id, 'jadwal_produksi_id' => $jadwal->id]);

        foreach ([Role::Admin, Role::KepalaSppg] as $role) {
            $this->login($role);
            $this->getJson('/api/v1/mutu')->assertOk();
            $this->getJson("/api/v1/mutu/{$m->id}")->assertOk();
            $this->postJson('/api/v1/mutu', $this->payload($jadwal))->assertForbidden();
            $this->patchJson("/api/v1/mutu/{$m->id}", ['catatan_organoleptik' => 'x'])->assertForbidden();
            $this->deleteJson("/api/v1/mutu/{$m->id}")->assertForbidden();
        }

        foreach ([RelawanUnit::Dapur, RelawanUnit::Distribusi] as $unit) {
            $this->login(Role::Relawan, null, $unit);
            $this->getJson("/api/v1/mutu/{$m->id}")->assertOk();
            $this->postJson('/api/v1/mutu', $this->payload($jadwal))->assertForbidden();
            $this->deleteJson("/api/v1/mutu/{$m->id}")->assertForbidden();
        }

        foreach ([Role::Akuntan, Role::StafGudang] as $role) {
            $this->login($role);
            $this->getJson('/api/v1/mutu')->assertForbidden();
            $this->getJson("/api/v1/mutu/{$m->id}")->assertForbidden();
            $this->postJson('/api/v1/mutu', $this->payload($jadwal))->assertForbidden();
        }

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/mutu')->assertUnauthorized();
    }

    public function test_lintas_sppg_dan_admin(): void
    {
        $milikB = PemeriksaanMutu::factory()->create(['sppg_id' => $this->sppgB->id]);
        PemeriksaanMutu::factory()->create(['sppg_id' => $this->sppgA->id]);

        $this->login(Role::AhliGizi);
        $this->getJson("/api/v1/mutu/{$milikB->id}")->assertNotFound();
        $this->patchJson("/api/v1/mutu/{$milikB->id}", ['catatan_organoleptik' => 'x'])->assertNotFound();
        $this->deleteJson("/api/v1/mutu/{$milikB->id}")->assertNotFound();
        $this->getJson('/api/v1/mutu')->assertOk()->assertJsonCount(1, 'data');

        $this->login(Role::Admin);
        $this->getJson('/api/v1/mutu')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/v1/mutu/{$milikB->id}")->assertOk();
        $this->getJson('/api/v1/mutu?sppg_id='.$this->sppgB->id)->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_hasil_tidak_layak_memancarkan_event_dan_menahan_distribusi(): void
    {
        $jadwal = $this->jadwal();
        $siap = JadwalDistribusi::factory()->siap()->create(['sppg_id' => $this->sppgA->id, 'jadwal_produksi_id' => $jadwal->id, 'jumlah_porsi' => 100]);
        JadwalDistribusi::factory()->create(['sppg_id' => $this->sppgA->id, 'jadwal_produksi_id' => $jadwal->id, 'jumlah_porsi' => 100]);
        $ahli = $this->login(Role::AhliGizi);
        $kepala = $this->user(Role::KepalaSppg);

        Event::fake([MutuTidakLayak::class]);
        $id = $this->postJson('/api/v1/mutu', $this->tidakLayak($jadwal))->assertCreated()->assertJsonPath('data.hasil', 'tidak_layak')->json('data.id');
        Event::assertDispatched(MutuTidakLayak::class, fn (MutuTidakLayak $e) => $e->sppgId === $this->sppgA->id
            && $e->pemeriksaanMutuId === $id && $e->jadwalProduksiId === $jadwal->id
            && $e->jumlahDistribusiDitahan === 2 && $e->pemeriksaId === $ahli->id
            && $e->tindakanKorektif === 'Masak ulang dan periksa ulang' && $e->tanggalProduksi === now()->toDateString());

        // Gerbang: kepala tidak dapat memberangkatkan distribusi.
        $this->masukPenuh($kepala);
        $this->patchJson("/api/v1/jadwal/distribusi/{$siap->id}", ['status' => 'berangkat'])->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->assertSame('siap', $siap->fresh()->status->value);

        // Hasil layak tidak memancarkan event.
        $this->masukPenuh($ahli);
        Event::fake([MutuTidakLayak::class]);
        $this->postJson('/api/v1/mutu', $this->payload($jadwal))->assertCreated();
        Event::assertNotDispatched(MutuTidakLayak::class);
        $this->masukPenuh($kepala);
        $this->patchJson("/api/v1/jadwal/distribusi/{$siap->id}", ['status' => 'berangkat'])->assertOk();
    }

    public function test_koreksi_menjadi_tidak_layak_memancarkan_event(): void
    {
        $jadwal = $this->jadwal();
        $m = PemeriksaanMutu::factory()->layak()->create(['sppg_id' => $this->sppgA->id, 'jadwal_produksi_id' => $jadwal->id]);
        $this->login(Role::AhliGizi);

        Event::fake([MutuTidakLayak::class]);
        $this->patchJson("/api/v1/mutu/{$m->id}", ['hasil' => 'tidak_layak', 'tindakan_korektif' => 'Buang batch'])->assertOk()->assertJsonPath('data.hasil', 'tidak_layak');
        Event::assertDispatchedTimes(MutuTidakLayak::class, 1);

        Event::fake([MutuTidakLayak::class]);
        $this->patchJson("/api/v1/mutu/{$m->id}", ['tindakan_korektif' => 'Buang batch dan bersihkan dapur'])->assertOk();
        Event::assertNotDispatched(MutuTidakLayak::class);
    }

    public function test_pemeriksaan_yang_memengaruhi_distribusi_tidak_dapat_dihapus_hanya_dikoreksi(): void
    {
        $jadwal = $this->jadwal();
        $lama = PemeriksaanMutu::factory()->layak()->create(['sppg_id' => $this->sppgA->id, 'jadwal_produksi_id' => $jadwal->id, 'waktu_pemeriksaan' => now()->subHours(3)]);
        $terbaru = PemeriksaanMutu::factory()->layak()->create(['sppg_id' => $this->sppgA->id, 'jadwal_produksi_id' => $jadwal->id, 'waktu_pemeriksaan' => now()->subHour()]);
        $dist = JadwalDistribusi::factory()->siap()->create(['sppg_id' => $this->sppgA->id, 'jadwal_produksi_id' => $jadwal->id, 'jumlah_porsi' => 100]);
        $ahli = $this->login(Role::AhliGizi);

        // Distribusi menunggu/siap: hanya pemeriksaan terbaru yang berlaku sebagai gerbang.
        $this->getJson("/api/v1/mutu/{$terbaru->id}")->assertOk()->assertJsonPath('data.memengaruhi_distribusi', true);
        $this->getJson("/api/v1/mutu/{$lama->id}")->assertOk()->assertJsonPath('data.memengaruhi_distribusi', false);
        $this->deleteJson("/api/v1/mutu/{$terbaru->id}")->assertUnprocessable()->assertJsonValidationErrors(['pemeriksaan']);
        $this->assertNull($terbaru->fresh()->deleted_at);

        // Koreksi tanpa catatan ditolak; dengan catatan diterima dan tercatat.
        $this->patchJson("/api/v1/mutu/{$terbaru->id}", ['suhu_makanan_c' => 70])->assertUnprocessable()->assertJsonValidationErrors(['catatan_koreksi']);
        $this->patchJson("/api/v1/mutu/{$terbaru->id}", ['suhu_makanan_c' => 70, 'catatan_koreksi' => 'Koreksi salah ketik suhu'])->assertOk()
            ->assertJsonPath('data.suhu_makanan_c', 70)->assertJsonPath('data.catatan_koreksi', 'Koreksi salah ketik suhu')->assertJsonPath('data.dikoreksi_oleh', $ahli->id);
        $log = AuditLog::query()->where('auditable_type', (new PemeriksaanMutu)->getMorphClass())->where('auditable_id', $terbaru->id)->where('event', 'updated')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertArrayHasKey('catatan_koreksi', $log->new_values);

        // Pemeriksaan lama (bukan gerbang) boleh dihapus selama distribusi belum berangkat.
        $this->deleteJson("/api/v1/mutu/{$lama->id}")->assertNoContent();

        // Setelah berangkat, semua pemeriksaan terkunci dari penghapusan.
        $dist->forceFill(['status' => 'berangkat'])->save();
        $lain = PemeriksaanMutu::factory()->layak()->create(['sppg_id' => $this->sppgA->id, 'jadwal_produksi_id' => $jadwal->id, 'waktu_pemeriksaan' => now()->subDays(1)]);
        $this->deleteJson("/api/v1/mutu/{$lain->id}")->assertUnprocessable();

        // Distribusi dibatalkan: tidak lagi memengaruhi.
        $dist->forceFill(['status' => 'dibatalkan'])->save();
        $this->deleteJson("/api/v1/mutu/{$lain->id}")->assertNoContent();
        $this->deleteJson("/api/v1/mutu/{$terbaru->id}")->assertNoContent();
    }

    public function test_pencarian_filter_paginasi(): void
    {
        $jadwal = $this->jadwal();
        PemeriksaanMutu::factory()->layak()->create(['sppg_id' => $this->sppgA->id, 'jadwal_produksi_id' => $jadwal->id, 'catatan_organoleptik' => 'Segar sekali', 'waktu_pemeriksaan' => '2026-10-20 07:00:00']);
        PemeriksaanMutu::factory()->tidakLayak()->create(['sppg_id' => $this->sppgA->id, 'jadwal_produksi_id' => $jadwal->id, 'waktu_pemeriksaan' => '2026-10-21 07:00:00']);
        PemeriksaanMutu::factory()->count(3)->layak()->create(['sppg_id' => $this->sppgA->id, 'catatan_organoleptik' => 'Biasa', 'waktu_pemeriksaan' => '2026-11-05 07:00:00']);
        $this->login(Role::KepalaSppg);

        $this->getJson('/api/v1/mutu?q=sekali')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/mutu?hasil=tidak_layak')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/mutu?jadwal_produksi_id='.$jadwal->id)->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/mutu?from=2026-10-21&to=2026-10-31')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/mutu?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 5);
    }

    public function test_draf_ai_dikonfirmasi(): void
    {
        $jadwal = $this->jadwal();
        $user = $this->user(Role::AhliGizi);
        $draf = AiDraft::factory()->create(['sppg_id' => $this->sppgA->id, 'user_id' => $user->id, 'module' => 'mutu']);
        $this->masukPenuh($user);

        $this->postJson('/api/v1/mutu', $this->payload($jadwal, ['ai_draft_id' => $draf->uuid]))->assertCreated();
        $this->assertSame('confirmed', $draf->fresh()->status->value);
    }
}
