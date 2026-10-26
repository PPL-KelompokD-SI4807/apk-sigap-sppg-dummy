<?php

namespace Tests\Feature\GrupB;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Models\AiDraft;
use App\Models\Armada;
use App\Models\BuktiDistribusi;
use App\Models\JadwalDistribusi;
use App\Models\JadwalProduksi;
use App\Models\PemeriksaanMutu;
use App\Models\Pengemudi;
use App\Models\TitikLayanan;
use App\Models\User;

class JadwalDistribusiTest extends GrupBTestCase
{
    private JadwalProduksi $produksi;

    private TitikLayanan $titik;

    protected function setUp(): void
    {
        parent::setUp();
        $this->produksi = JadwalProduksi::factory()->create(['sppg_id' => $this->sppgA->id, 'target_porsi' => 1000, 'tanggal_produksi' => now()->addDay()->toDateString()]);
        $this->titik = TitikLayanan::factory()->create(['sppg_id' => $this->sppgA->id]);
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'jadwal_produksi_id' => $this->produksi->id, 'titik_layanan_id' => $this->titik->id,
            'jam_berangkat' => '08:00', 'jam_rencana_tiba' => '08:45', 'jumlah_porsi' => 300,
        ], $override);
    }

    private function dist(array $attrs = []): JadwalDistribusi
    {
        return JadwalDistribusi::factory()->create(array_merge([
            'sppg_id' => $this->sppgA->id, 'jadwal_produksi_id' => $this->produksi->id, 'titik_layanan_id' => $this->titik->id, 'jumlah_porsi' => 100,
        ], $attrs));
    }

    public function test_kepala_crud_jadwal_distribusi(): void
    {
        $armada = Armada::factory()->create(['sppg_id' => $this->sppgA->id]);
        $sopir = Pengemudi::factory()->create(['sppg_id' => $this->sppgA->id]);
        $kepala = $this->login(Role::KepalaSppg);

        $id = $this->postJson('/api/v1/jadwal/distribusi', $this->payload(['armada_id' => $armada->id, 'pengemudi_id' => $sopir->id]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'menunggu')
            ->assertJsonPath('data.sppg_id', $this->sppgA->id)
            ->assertJsonPath('data.dibuat_oleh', $kepala->id)
            ->assertJsonPath('data.tanggal_distribusi', $this->produksi->tanggal_produksi->toDateString())
            ->assertJsonPath('data.jam_berangkat', '08:00')
            ->assertJsonPath('data.armada.plat_nomor', $armada->plat_nomor)
            ->assertJsonPath('data.titik_layanan.id', $this->titik->id)
            ->assertJsonPath('data.layak_distribusi', false)
            ->json('data.id');

        $this->getJson("/api/v1/jadwal/distribusi/{$id}")->assertOk()->assertJsonPath('data.jumlah_porsi', 300);
        $this->patchJson("/api/v1/jadwal/distribusi/{$id}", ['jumlah_porsi' => 350, 'catatan' => 'Tambah'])->assertOk()->assertJsonPath('data.jumlah_porsi', 350);
        $this->deleteJson("/api/v1/jadwal/distribusi/{$id}")->assertNoContent();
        $this->assertSoftDeleted('jadwal_distribusis', ['id' => $id]);
        $this->getJson("/api/v1/jadwal/distribusi/{$id}")->assertNotFound();
    }

    public function test_validasi_422(): void
    {
        $titikB = TitikLayanan::factory()->create(['sppg_id' => $this->sppgB->id]);
        $armadaB = Armada::factory()->create(['sppg_id' => $this->sppgB->id]);
        $servis = Armada::factory()->servis()->create(['sppg_id' => $this->sppgA->id]);
        $nonaktif = Pengemudi::factory()->create(['sppg_id' => $this->sppgA->id, 'is_active' => false]);
        $produksiB = JadwalProduksi::factory()->create(['sppg_id' => $this->sppgB->id]);
        $batal = JadwalProduksi::factory()->create(['sppg_id' => $this->sppgA->id, 'status' => 'dibatalkan']);
        $armada = Armada::factory()->create(['sppg_id' => $this->sppgA->id]);
        $this->login(Role::KepalaSppg);
        $url = '/api/v1/jadwal/distribusi';

        $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors(['jadwal_produksi_id', 'titik_layanan_id', 'jumlah_porsi']);
        $this->postJson($url, $this->payload(['titik_layanan_id' => $titikB->id]))->assertUnprocessable()->assertJsonValidationErrors(['titik_layanan_id']);
        $this->postJson($url, $this->payload(['armada_id' => $armadaB->id]))->assertUnprocessable()->assertJsonValidationErrors(['armada_id']);
        $this->postJson($url, $this->payload(['jadwal_produksi_id' => $produksiB->id]))->assertUnprocessable()->assertJsonValidationErrors(['jadwal_produksi_id']);
        $this->postJson($url, $this->payload(['jadwal_produksi_id' => $batal->id]))->assertUnprocessable()->assertJsonValidationErrors(['jadwal_produksi_id']);
        $this->postJson($url, $this->payload(['armada_id' => $servis->id]))->assertUnprocessable()->assertJsonValidationErrors(['armada_id']);
        $this->postJson($url, $this->payload(['pengemudi_id' => $nonaktif->id]))->assertUnprocessable()->assertJsonValidationErrors(['pengemudi_id']);
        $this->postJson($url, $this->payload(['armada_id' => $armada->id, 'jam_berangkat' => null, 'jam_rencana_tiba' => null]))->assertUnprocessable()->assertJsonValidationErrors(['jam_berangkat']);
        $this->postJson($url, $this->payload(['jam_rencana_tiba' => '07:00']))->assertUnprocessable()->assertJsonValidationErrors(['jam_rencana_tiba']);
        $this->postJson($url, $this->payload(['jam_berangkat' => '8am']))->assertUnprocessable()->assertJsonValidationErrors(['jam_berangkat']);
        $this->postJson($url, $this->payload(['jumlah_porsi' => 0]))->assertUnprocessable()->assertJsonValidationErrors(['jumlah_porsi']);
        $this->postJson($url, $this->payload(['tanggal_distribusi' => now()->toDateString()]))->assertUnprocessable()->assertJsonValidationErrors(['tanggal_distribusi']);
        $this->postJson($url, $this->payload(['status' => 'berangkat']))->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->assertSame(0, JadwalDistribusi::query()->count());
    }

    public function test_pengemudi_dengan_sim_kedaluwarsa_ditolak(): void
    {
        $kedaluwarsa = Pengemudi::factory()->create(['sppg_id' => $this->sppgA->id, 'sim_berlaku_hingga' => now()->subDay()->toDateString()]);
        $berlaku = Pengemudi::factory()->create(['sppg_id' => $this->sppgA->id, 'sim_berlaku_hingga' => now()->addMonth()->toDateString()]);
        $tanpaSim = Pengemudi::factory()->create(['sppg_id' => $this->sppgA->id, 'sim_berlaku_hingga' => null]);
        $this->login(Role::KepalaSppg);

        $res = $this->postJson('/api/v1/jadwal/distribusi', $this->payload(['pengemudi_id' => $kedaluwarsa->id]))->assertUnprocessable()->assertJsonValidationErrors(['pengemudi_id']);
        $this->assertStringContainsString('SIM pengemudi', $res->json('errors.pengemudi_id.0'));
        $this->postJson('/api/v1/jadwal/distribusi', $this->payload(['pengemudi_id' => $berlaku->id, 'jumlah_porsi' => 100]))->assertCreated();
        $this->postJson('/api/v1/jadwal/distribusi', $this->payload(['pengemudi_id' => $tanpaSim->id, 'jam_berangkat' => '12:00', 'jam_rencana_tiba' => '12:30', 'jumlah_porsi' => 100]))->assertCreated();
    }

    public function test_total_porsi_distribusi_tidak_melebihi_porsi_produksi(): void
    {
        $this->dist(['jumlah_porsi' => 600]);
        $this->dist(['jumlah_porsi' => 300, 'status' => 'dibatalkan']);
        $this->login(Role::KepalaSppg);

        $res = $this->postJson('/api/v1/jadwal/distribusi', $this->payload(['jumlah_porsi' => 401]))->assertUnprocessable();
        $this->assertSame('Total porsi distribusi menjadi 1001 (600 sudah terjadwal + 401 baru), melebihi porsi produksi 1000. Sisa porsi 400.', $res->json('errors.jumlah_porsi.0'));
        $id = $this->postJson('/api/v1/jadwal/distribusi', $this->payload(['jumlah_porsi' => 400]))->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/jadwal/distribusi/{$id}", ['jumlah_porsi' => 401])->assertUnprocessable()->assertJsonValidationErrors(['jumlah_porsi']);
        $this->patchJson("/api/v1/jadwal/distribusi/{$id}", ['jumlah_porsi' => 400])->assertOk();
    }

    public function test_armada_dan_pengemudi_tidak_boleh_bentrok_jam(): void
    {
        $armada = Armada::factory()->create(['sppg_id' => $this->sppgA->id]);
        $sopir = Pengemudi::factory()->create(['sppg_id' => $this->sppgA->id]);
        $titik2 = TitikLayanan::factory()->create(['sppg_id' => $this->sppgA->id]);
        $this->dist(['armada_id' => $armada->id, 'pengemudi_id' => $sopir->id, 'jam_berangkat' => '08:00', 'jam_rencana_tiba' => '09:00', 'jumlah_porsi' => 100]);
        $lainDibatalkan = $this->dist(['armada_id' => $armada->id, 'jam_berangkat' => '10:00', 'jam_rencana_tiba' => '11:00', 'status' => 'dibatalkan']);
        $this->login(Role::KepalaSppg);
        $url = '/api/v1/jadwal/distribusi';

        // Tumpang tindih armada.
        $this->postJson($url, $this->payload(['titik_layanan_id' => $titik2->id, 'armada_id' => $armada->id, 'jam_berangkat' => '08:30', 'jam_rencana_tiba' => '09:30', 'jumlah_porsi' => 50]))
            ->assertUnprocessable()->assertJsonValidationErrors(['armada_id']);
        // Tumpang tindih pengemudi dengan armada berbeda.
        $armada2 = Armada::factory()->create(['sppg_id' => $this->sppgA->id]);
        $this->postJson($url, $this->payload(['armada_id' => $armada2->id, 'pengemudi_id' => $sopir->id, 'jam_berangkat' => '07:30', 'jam_rencana_tiba' => '08:15', 'jumlah_porsi' => 50]))
            ->assertUnprocessable()->assertJsonValidationErrors(['pengemudi_id']);
        // Tanpa jam tiba: dianggap 60 menit.
        $this->postJson($url, $this->payload(['armada_id' => $armada->id, 'jam_berangkat' => '08:50', 'jam_rencana_tiba' => null, 'jumlah_porsi' => 50]))
            ->assertUnprocessable()->assertJsonValidationErrors(['armada_id']);
        // Berurutan (mulai tepat saat yang lain selesai) dan jadwal batal tidak menghalangi.
        $this->postJson($url, $this->payload(['armada_id' => $armada->id, 'pengemudi_id' => $sopir->id, 'jam_berangkat' => '09:00', 'jam_rencana_tiba' => '09:45', 'jumlah_porsi' => 50]))->assertCreated();
        $this->postJson($url, $this->payload(['armada_id' => $armada->id, 'jam_berangkat' => '10:15', 'jam_rencana_tiba' => '10:45', 'jumlah_porsi' => 50]))->assertCreated();
        $this->assertNotNull($lainDibatalkan);

        // Update ke jam bentrok ditolak.
        $id = $this->postJson($url, $this->payload(['armada_id' => $armada->id, 'jam_berangkat' => '12:00', 'jam_rencana_tiba' => '12:30', 'jumlah_porsi' => 50]))->assertCreated()->json('data.id');
        $this->patchJson("{$url}/{$id}", ['jam_berangkat' => '08:10', 'jam_rencana_tiba' => '08:40'])->assertUnprocessable()->assertJsonValidationErrors(['armada_id']);
        // Armada sama pada tanggal lain tidak bentrok.
        $this->patchJson("{$url}/{$id}", ['tanggal_distribusi' => now()->addDays(2)->toDateString(), 'jam_berangkat' => '08:10', 'jam_rencana_tiba' => '08:40'])->assertOk();
    }

    public function test_status_transisi(): void
    {
        $this->login(Role::KepalaSppg);
        $d = $this->dist();
        $url = "/api/v1/jadwal/distribusi/{$d->id}";

        $this->patchJson($url, ['status' => 'diterima'])->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->patchJson($url, ['status' => 'berangkat'])->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->patchJson($url, ['status' => 'siap'])->assertOk()->assertJsonPath('data.status', 'siap');
        $this->patchJson($url, ['status' => 'menunggu'])->assertUnprocessable();

        $dibatalkan = $this->dist();
        $this->patchJson("/api/v1/jadwal/distribusi/{$dibatalkan->id}", ['status' => 'dibatalkan'])->assertOk();
        $this->patchJson("/api/v1/jadwal/distribusi/{$dibatalkan->id}", ['status' => 'siap'])->assertUnprocessable();
    }

    public function test_mutu_tidak_layak_menahan_keberangkatan_distribusi(): void
    {
        $d = $this->dist(['status' => 'siap']);
        $this->login(Role::KepalaSppg);
        $url = "/api/v1/jadwal/distribusi/{$d->id}";

        // Belum ada pemeriksaan: ditahan.
        $res = $this->patchJson($url, ['status' => 'berangkat'])->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->assertStringContainsString('belum ada pemeriksaan mutu', $res->json('errors.status.0'));
        $this->assertSame('siap', $d->fresh()->status->value);

        // Tidak layak: ditahan.
        $tidakLayak = PemeriksaanMutu::factory()->tidakLayak()->create(['sppg_id' => $this->sppgA->id, 'jadwal_produksi_id' => $this->produksi->id, 'waktu_pemeriksaan' => now()->subHour()]);
        $res = $this->patchJson($url, ['status' => 'berangkat'])->assertUnprocessable();
        $this->assertStringContainsString('Tidak Layak', $res->json('errors.status.0'));
        $this->assertFalse($this->produksi->fresh()->isLayakDistribusi());
        $this->assertSame('siap', $d->fresh()->status->value);

        // Pemeriksaan ulang Layak membuka gerbang.
        PemeriksaanMutu::factory()->layak()->create(['sppg_id' => $this->sppgA->id, 'jadwal_produksi_id' => $this->produksi->id, 'waktu_pemeriksaan' => now()]);
        $this->assertTrue($this->produksi->fresh()->isLayakDistribusi());
        $this->getJson($url)->assertOk()->assertJsonPath('data.layak_distribusi', true);
        $this->patchJson($url, ['status' => 'berangkat'])->assertOk()->assertJsonPath('data.status', 'berangkat');
        $this->assertNotNull($tidakLayak);

        // Setelah berangkat, data perencanaan terkunci; status lanjut diterima.
        $this->patchJson($url, ['jumlah_porsi' => 10])->assertUnprocessable()->assertJsonValidationErrors(['jumlah_porsi']);
        $this->patchJson($url, ['status' => 'diterima'])->assertOk()->assertJsonPath('data.status', 'diterima');
    }

    public function test_pemeriksaan_tidak_layak_terbaru_menimpa_layak_sebelumnya(): void
    {
        $d = $this->dist(['status' => 'siap']);
        PemeriksaanMutu::factory()->layak()->create(['sppg_id' => $this->sppgA->id, 'jadwal_produksi_id' => $this->produksi->id, 'waktu_pemeriksaan' => now()->subHours(2)]);
        PemeriksaanMutu::factory()->tidakLayak()->create(['sppg_id' => $this->sppgA->id, 'jadwal_produksi_id' => $this->produksi->id, 'waktu_pemeriksaan' => now()->subHour()]);
        $this->login(Role::KepalaSppg);

        $this->patchJson("/api/v1/jadwal/distribusi/{$d->id}", ['status' => 'berangkat'])->assertUnprocessable()->assertJsonValidationErrors(['status']);
    }

    public function test_hak_akses_dan_aturan_relawan_distribusi(): void
    {
        $relawan = $this->user(Role::Relawan, null, RelawanUnit::Distribusi);
        $milik = $this->dist(['dibuat_oleh' => $relawan->id]);
        $milikLain = $this->dist();
        $kemarin = $this->dist(['dibuat_oleh' => $relawan->id]);
        $kemarin->forceFill(['created_at' => now()->subDay()])->saveQuietly();

        $this->login(Role::Akuntan);
        $this->getJson('/api/v1/jadwal/distribusi')->assertForbidden();

        $this->login(Role::StafGudang);
        $this->getJson('/api/v1/jadwal/distribusi')->assertOk();
        $this->postJson('/api/v1/jadwal/distribusi', $this->payload())->assertForbidden();
        $this->patchJson("/api/v1/jadwal/distribusi/{$milik->id}", ['catatan' => 'x'])->assertForbidden();

        $this->login(Role::Admin);
        $this->getJson('/api/v1/jadwal/distribusi')->assertOk();
        $this->postJson('/api/v1/jadwal/distribusi', $this->payload())->assertForbidden();

        $this->login(Role::Relawan, null, RelawanUnit::Dapur);
        $this->getJson('/api/v1/jadwal/distribusi')->assertForbidden();
        $this->postJson('/api/v1/jadwal/distribusi', $this->payload())->assertForbidden();

        $this->masukPenuh($relawan);
        $this->getJson('/api/v1/jadwal/distribusi')->assertOk();
        $id = $this->postJson('/api/v1/jadwal/distribusi', $this->payload(['jumlah_porsi' => 50]))->assertCreated()
            ->assertJsonPath('data.petugas_id', $relawan->id)->assertJsonPath('data.dibuat_oleh', $relawan->id)->json('data.id');
        $this->patchJson("/api/v1/jadwal/distribusi/{$id}", ['catatan' => 'ok'])->assertOk();
        $this->patchJson("/api/v1/jadwal/distribusi/{$milik->id}", ['catatan' => 'ok'])->assertOk();
        $this->patchJson("/api/v1/jadwal/distribusi/{$milikLain->id}", ['catatan' => 'ok'])->assertForbidden();
        $this->patchJson("/api/v1/jadwal/distribusi/{$kemarin->id}", ['catatan' => 'ok'])->assertForbidden();
        $this->deleteJson("/api/v1/jadwal/distribusi/{$id}")->assertForbidden();
        $this->getJson('/api/v1/jadwal')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/jadwal/distribusi')->assertUnauthorized();
    }

    public function test_lintas_sppg_dan_admin(): void
    {
        $produksiB = JadwalProduksi::factory()->create(['sppg_id' => $this->sppgB->id]);
        $milikB = JadwalDistribusi::factory()->create(['sppg_id' => $this->sppgB->id, 'jadwal_produksi_id' => $produksiB->id, 'jumlah_porsi' => 10]);
        $this->dist();

        $this->login(Role::KepalaSppg);
        $this->getJson("/api/v1/jadwal/distribusi/{$milikB->id}")->assertNotFound();
        $this->patchJson("/api/v1/jadwal/distribusi/{$milikB->id}", ['catatan' => 'x'])->assertNotFound();
        $this->deleteJson("/api/v1/jadwal/distribusi/{$milikB->id}")->assertNotFound();
        $this->getJson('/api/v1/jadwal/distribusi')->assertOk()->assertJsonCount(1, 'data');

        $this->login(Role::Admin);
        $this->getJson('/api/v1/jadwal/distribusi')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/v1/jadwal/distribusi/{$milikB->id}")->assertOk();
    }

    public function test_hapus_ditolak_setelah_berangkat_atau_ada_bukti(): void
    {
        $berangkat = $this->dist(['status' => 'berangkat']);
        $bukti = $this->dist(['status' => 'siap']);
        BuktiDistribusi::factory()->create(['sppg_id' => $this->sppgA->id, 'jadwal_distribusi_id' => $bukti->id]);
        $this->login(Role::KepalaSppg);

        $this->deleteJson("/api/v1/jadwal/distribusi/{$berangkat->id}")->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->deleteJson("/api/v1/jadwal/distribusi/{$bukti->id}")->assertUnprocessable()->assertJsonValidationErrors(['jadwal']);
    }

    public function test_pencarian_filter_paginasi(): void
    {
        $armada = Armada::factory()->create(['sppg_id' => $this->sppgA->id]);
        $this->dist(['catatan' => 'Rute utara', 'armada_id' => $armada->id, 'jam_berangkat' => '08:00', 'jam_rencana_tiba' => '09:00']);
        $this->dist(['status' => 'siap', 'jam_berangkat' => '10:00']);
        $this->dist(['tanggal_distribusi' => '2027-01-05']);
        $this->login(Role::KepalaSppg);

        $this->getJson('/api/v1/jadwal/distribusi?q=utara')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/jadwal/distribusi?status=siap')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/jadwal/distribusi?armada_id='.$armada->id)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/jadwal/distribusi?jadwal_produksi_id='.$this->produksi->id)->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/jadwal/distribusi?from=2027-01-01')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/jadwal/distribusi?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3);
    }

    public function test_petugas_wajib_relawan_distribusi_aktif_sppg_sama(): void
    {
        $sah = $this->user(Role::Relawan, null, RelawanUnit::Distribusi);
        $ditolak = [
            'relawan dapur' => $this->user(Role::Relawan, null, RelawanUnit::Dapur),
            'bukan relawan' => $this->user(Role::AhliGizi),
            'nonaktif' => tap($this->user(Role::Relawan, null, RelawanUnit::Distribusi), fn (User $u) => $u->forceFill(['is_active' => false])->save()),
            'sppg lain' => $this->user(Role::Relawan, $this->sppgB, RelawanUnit::Distribusi),
            'terhapus' => tap($this->user(Role::Relawan, null, RelawanUnit::Distribusi), fn (User $u) => $u->delete()),
        ];
        $this->login(Role::KepalaSppg);

        foreach ($ditolak as $alasan => $petugas) {
            $this->postJson('/api/v1/jadwal/distribusi', $this->payload(['petugas_id' => $petugas->id]))
                ->assertUnprocessable()->assertJsonValidationErrors(['petugas_id']);
        }

        $id = $this->postJson('/api/v1/jadwal/distribusi', $this->payload(['petugas_id' => $sah->id]))
            ->assertCreated()->assertJsonPath('data.petugas_id', $sah->id)->json('data.id');

        // Update memakai aturan yang sama.
        $this->patchJson("/api/v1/jadwal/distribusi/{$id}", ['petugas_id' => $ditolak['relawan dapur']->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['petugas_id']);
    }

    public function test_draf_ai_dikonfirmasi(): void
    {
        $user = $this->user(Role::KepalaSppg);
        $draf = AiDraft::factory()->create(['sppg_id' => $this->sppgA->id, 'user_id' => $user->id, 'module' => 'jadwal', 'payload' => ['_resource' => 'distribusi']]);
        $drafProduksi = AiDraft::factory()->create(['sppg_id' => $this->sppgA->id, 'user_id' => $user->id, 'module' => 'jadwal']);
        $this->masukPenuh($user);

        // Draf jadwal PRODUKSI (tanpa penanda) tidak dapat dikonfirmasi lewat endpoint distribusi.
        $this->postJson('/api/v1/jadwal/distribusi', $this->payload(['ai_draft_id' => $drafProduksi->uuid]))
            ->assertUnprocessable()->assertJsonValidationErrors(['ai_draft_id']);
        $this->assertSame('pending', $drafProduksi->fresh()->status->value);

        $this->postJson('/api/v1/jadwal/distribusi', $this->payload(['ai_draft_id' => $draf->uuid]))->assertCreated();
        $this->assertSame('confirmed', $draf->fresh()->status->value);
    }
}
