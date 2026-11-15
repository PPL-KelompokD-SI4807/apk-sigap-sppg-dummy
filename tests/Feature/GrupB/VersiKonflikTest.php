<?php

namespace Tests\Feature\GrupB;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Models\JadwalDistribusi;
use App\Models\JadwalProduksi;
use App\Models\LogProduksi;
use App\Models\PemeriksaanMutu;
use Illuminate\Testing\TestResponse;

/**
 * Issue #127: penanda `versi` (kunci optimistik, pola Pengeluaran/PembelianBahan) untuk jadwal produksi,
 * jadwal distribusi, log produksi, dan pemeriksaan mutu. Basi -> 409 `versi_tidak_cocok` + `versi_server`.
 */
class VersiKonflikTest extends GrupBTestCase
{
    private function jadwal(): JadwalProduksi
    {
        return JadwalProduksi::factory()->create(['sppg_id' => $this->sppgA->id, 'target_porsi' => 1000, 'tanggal_produksi' => now()->toDateString()]);
    }

    private function assertKonflik(TestResponse $res, int $versiServer): void
    {
        $res->assertStatus(409)
            ->assertJsonPath('alasan', 'versi_tidak_cocok')
            ->assertJsonPath('versi_server', $versiServer)
            ->assertJsonStructure(['message']);
    }

    public function test_jadwal_produksi_versi_di_respons_dan_409_pada_versi_basi(): void
    {
        $jadwal = $this->jadwal();
        $this->login(Role::KepalaSppg);
        $url = "/api/v1/jadwal/{$jadwal->id}";

        $this->getJson($url)->assertOk()->assertJsonPath('data.versi', 1);

        // Sukses pada versi benar: versi naik.
        $this->patchJson($url, ['versi' => 1, 'catatan' => 'Revisi A'])->assertOk()
            ->assertJsonPath('data.versi', 2)->assertJsonPath('data.catatan', 'Revisi A');

        // Penyunting kedua masih memegang versi 1 -> 409, data tidak berubah.
        $this->assertKonflik($this->patchJson($url, ['versi' => 1, 'catatan' => 'Revisi B']), 2);
        $this->assertSame('Revisi A', $jadwal->fresh()->catatan);
        $this->assertSame(2, $jadwal->fresh()->versi);

        // PUT juga dijaga; versi benar lolos.
        $this->putJson($url, ['versi' => 2, 'catatan' => 'Revisi C'])->assertOk()->assertJsonPath('data.versi', 3);
        $this->assertKonflik($this->putJson($url, ['versi' => 2, 'catatan' => 'Revisi D']), 3);
    }

    public function test_jadwal_produksi_ubah_status_dengan_versi_tidak_regresi(): void
    {
        $jadwal = $this->jadwal();
        $this->login(Role::KepalaSppg);
        $url = "/api/v1/jadwal/{$jadwal->id}";

        $this->patchJson($url, ['versi' => 1, 'status' => 'dimasak'])->assertOk()
            ->assertJsonPath('data.status', 'dimasak')->assertJsonPath('data.versi', 2);
        // Transisi tidak sah tetap 422 (bukan 409) selama versi cocok; versi tidak naik karena rollback.
        $this->patchJson($url, ['versi' => 2, 'status' => 'direncanakan'])->assertUnprocessable();
        $this->assertSame(2, $jadwal->fresh()->versi);
        // Tanpa versi (klien lama): perilaku lama dipertahankan.
        $this->patchJson($url, ['status' => 'selesai'])->assertOk()->assertJsonPath('data.status', 'selesai')->assertJsonPath('data.versi', 3);
    }

    public function test_jadwal_produksi_versi_tidak_valid_422_dan_tanpa_hak_403(): void
    {
        $jadwal = $this->jadwal();
        $url = "/api/v1/jadwal/{$jadwal->id}";

        $this->login(Role::KepalaSppg);
        $this->patchJson($url, ['versi' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors(['versi']);
        $this->patchJson($url, ['versi' => 0])->assertUnprocessable()->assertJsonValidationErrors(['versi']);

        // Tanpa hak ubah tetap 403 (bukan 409) walau versi basi.
        $this->login(Role::Akuntan);
        $this->patchJson($url, ['versi' => 99, 'catatan' => 'x'])->assertForbidden();
        $this->login(Role::Relawan, null, RelawanUnit::Distribusi);
        $this->patchJson($url, ['versi' => 99, 'catatan' => 'x'])->assertForbidden();
    }

    public function test_perubahan_status_oleh_alur_lain_menaikkan_versi_jadwal_produksi(): void
    {
        $jadwal = $this->jadwal();
        $dist = JadwalDistribusi::factory()->create(['sppg_id' => $this->sppgA->id, 'jadwal_produksi_id' => $jadwal->id, 'jumlah_porsi' => 100]);
        $this->login(Role::KepalaSppg);

        // Pembatalan jadwal produksi membatalkan distribusinya: versi distribusi ikut naik.
        $this->patchJson("/api/v1/jadwal/{$jadwal->id}", ['versi' => 1, 'status' => 'dibatalkan'])->assertOk()->assertJsonPath('data.versi', 2);
        $this->assertSame(2, $dist->fresh()->versi);
        $this->assertKonflik($this->patchJson("/api/v1/jadwal/distribusi/{$dist->id}", ['versi' => 1, 'catatan' => 'x']), 2);
    }

    public function test_jadwal_distribusi_versi_dan_409(): void
    {
        $jadwal = $this->jadwal();
        $dist = JadwalDistribusi::factory()->create(['sppg_id' => $this->sppgA->id, 'jadwal_produksi_id' => $jadwal->id, 'jumlah_porsi' => 100]);
        $this->login(Role::KepalaSppg);
        $url = "/api/v1/jadwal/distribusi/{$dist->id}";

        $this->getJson($url)->assertOk()->assertJsonPath('data.versi', 1);
        $this->patchJson($url, ['versi' => 1, 'status' => 'siap'])->assertOk()
            ->assertJsonPath('data.status', 'siap')->assertJsonPath('data.versi', 2);
        $this->assertKonflik($this->patchJson($url, ['versi' => 1, 'catatan' => 'basi']), 2);
        $this->assertNull($dist->fresh()->catatan);
        $this->putJson($url, ['versi' => 2, 'catatan' => 'ok'])->assertOk()->assertJsonPath('data.versi', 3);

        // Distribusi baru lewat POST langsung berversi 1.
        $this->postJson('/api/v1/jadwal/distribusi', [
            'jadwal_produksi_id' => $jadwal->id, 'titik_layanan_id' => $dist->titik_layanan_id, 'jumlah_porsi' => 50, 'jam_berangkat' => '08:00', 'jam_rencana_tiba' => '09:00',
        ])->assertCreated()->assertJsonPath('data.versi', 1);

        $this->login(Role::Akuntan);
        $this->patchJson($url, ['versi' => 99, 'catatan' => 'x'])->assertForbidden();
    }

    public function test_log_produksi_versi_dan_409(): void
    {
        $jadwal = $this->jadwal();
        $relawan = $this->login(Role::Relawan, null, RelawanUnit::Dapur);

        $id = $this->postJson('/api/v1/log-produksi', ['jadwal_produksi_id' => $jadwal->id, 'realisasi_porsi' => 900])
            ->assertCreated()->assertJsonPath('data.versi', 1)->json('data.id');
        $url = "/api/v1/log-produksi/{$id}";

        $this->getJson($url)->assertOk()->assertJsonPath('data.versi', 1);
        $this->patchJson($url, ['versi' => 1, 'realisasi_porsi' => 950])->assertOk()
            ->assertJsonPath('data.realisasi_porsi', 950)->assertJsonPath('data.versi', 2);
        $this->assertKonflik($this->patchJson($url, ['versi' => 1, 'realisasi_porsi' => 100]), 2);
        $this->assertSame(950, LogProduksi::query()->findOrFail($id)->realisasi_porsi);
        $this->putJson($url, ['versi' => 2, 'kendala' => 'Gas'])->assertOk()->assertJsonPath('data.versi', 3);

        // Validasi bisnis (422) tetap berlaku bila versi cocok.
        $this->patchJson($url, ['versi' => 3, 'realisasi_porsi' => 999999])->assertUnprocessable();

        // 403: relawan dapur lain bukan pencatat; peran tanpa hak.
        $this->login(Role::Relawan, null, RelawanUnit::Dapur);
        $this->patchJson($url, ['versi' => 99, 'kendala' => 'x'])->assertForbidden();
        $this->login(Role::Akuntan);
        $this->patchJson($url, ['versi' => 99, 'kendala' => 'x'])->assertForbidden();
        $this->assertNotNull($relawan);
    }

    public function test_pemeriksaan_mutu_versi_dan_409(): void
    {
        $jadwal = $this->jadwal();
        $m = PemeriksaanMutu::factory()->layak()->create(['sppg_id' => $this->sppgA->id, 'jadwal_produksi_id' => $jadwal->id]);
        $this->login(Role::AhliGizi);
        $url = "/api/v1/mutu/{$m->id}";

        $this->getJson($url)->assertOk()->assertJsonPath('data.versi', 1);
        $this->patchJson($url, ['versi' => 1, 'catatan_organoleptik' => 'Baik'])->assertOk()
            ->assertJsonPath('data.catatan_organoleptik', 'Baik')->assertJsonPath('data.versi', 2);
        $this->assertKonflik($this->patchJson($url, ['versi' => 1, 'catatan_organoleptik' => 'Basi']), 2);
        $this->assertSame('Baik', $m->fresh()->catatan_organoleptik);
        $this->putJson($url, ['versi' => 2, 'catatan_organoleptik' => 'Lagi'])->assertOk()->assertJsonPath('data.versi', 3);

        $this->login(Role::KepalaSppg);
        $this->patchJson($url, ['versi' => 99, 'catatan_organoleptik' => 'x'])->assertForbidden();
        $this->login(Role::Relawan, null, RelawanUnit::Dapur);
        $this->patchJson($url, ['versi' => 99, 'catatan_organoleptik' => 'x'])->assertForbidden();
    }

    public function test_lintas_sppg_tetap_tidak_dapat_mengubah_walau_versi_cocok(): void
    {
        $milikB = JadwalProduksi::factory()->create(['sppg_id' => $this->sppgB->id]);
        $this->login(Role::KepalaSppg);

        $res = $this->patchJson("/api/v1/jadwal/{$milikB->id}", ['versi' => 1, 'catatan' => 'x']);
        $this->assertContains($res->getStatusCode(), [403, 404]);
        $this->assertNull($milikB->fresh()->catatan);
    }
}
