<?php

namespace Tests\Feature\Unggahan;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Events\MutuTidakLayak;
use App\Models\FeedbackKeluhan;
use App\Models\JadwalProduksi;
use App\Models\Lampiran;
use App\Models\LogProduksi;
use App\Models\PemeriksaanMutu;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\GrupB\GrupBTestCase;
use Tests\Support\GambarUji;

/** Lampiran (foto) untuk keluhan, mutu, dan log-produksi: unggah tertunda + lampiran_ids. */
class LampiranTertundaTest extends GrupBTestCase
{
    use GambarUji;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('uploads');
        Event::fake([MutuTidakLayak::class]);
    }

    private function unggah(string $modul, array $extra = []): TestResponse
    {
        return $this->post('/api/v1/lampiran', array_merge(['modul' => $modul, 'berkas' => $this->fotoJpeg()], $extra), ['Accept' => 'application/json']);
    }

    private function idLampiran(string $modul, array $extra = []): int
    {
        return (int) $this->unggah($modul, $extra)->assertCreated()->json('data.id');
    }

    private function jadwal(?int $sppgId = null): JadwalProduksi
    {
        return JadwalProduksi::factory()->create(['sppg_id' => $sppgId ?? $this->sppgA->id, 'target_porsi' => 1000, 'tanggal_produksi' => now()->toDateString()]);
    }

    /** @return array<string, mixed> */
    private function keluhan(array $o = []): array
    {
        return array_merge(['sumber' => 'guru', 'kategori' => 'rasa', 'deskripsi' => 'Sayur terlalu lembek'], $o);
    }

    /** @return array<string, mixed> */
    private function mutu(JadwalProduksi $j, array $o = []): array
    {
        return array_merge([
            'jadwal_produksi_id' => $j->id, 'suhu_makanan_c' => 70, 'kebersihan_dapur' => true, 'kebersihan_petugas' => true,
            'rasa_baik' => true, 'aroma_baik' => true, 'tampilan_baik' => true, 'hasil' => 'layak',
        ], $o);
    }

    public function test_unggah_tertunda_belum_terikat_lalu_diikat_saat_buat_keluhan(): void
    {
        $relawan = $this->login(Role::Relawan, null, RelawanUnit::Distribusi);

        $res = $this->unggah('keluhan')->assertCreated()
            ->assertJsonPath('data.kategori', 'foto')
            ->assertJsonPath('data.mime', 'image/jpeg')
            ->assertJsonPath('data.uploaded_by', $relawan->id);
        $id = $res->json('data.id');
        $lampiran = Lampiran::query()->findOrFail($id);
        $this->assertNull($lampiran->lampiranable_type);
        $this->assertNull($lampiran->lampiranable_id);
        $this->assertSame('keluhan', $lampiran->modul);
        $this->assertSame($this->sppgA->id, $lampiran->sppg_id);
        Storage::disk('uploads')->assertExists($lampiran->path);

        $respons = $this->postJson('/api/v1/keluhan', $this->keluhan(['lampiran_ids' => [$id], 'foto_path' => 'x/y.jpg']))->assertCreated()
            ->assertJsonCount(1, 'data.lampiran')
            ->assertJsonPath('data.lampiran.0.id', $id)
            ->assertJsonPath('data.lampiran.0.url', "/api/v1/lampiran/{$id}")
            ->assertJsonPath('data.lampiran.0.mime', 'image/jpeg')
            ->assertJsonMissingPath('data.foto_path')
            ->assertJsonMissingPath('data.lampiran.0.path');
        $this->assertSame($respons->json('data.lampiran.0.ukuran'), $lampiran->ukuran);

        $keluhan = FeedbackKeluhan::query()->findOrFail($respons->json('data.id'));
        $this->assertNull($keluhan->foto_path);
        $lampiran->refresh();
        $this->assertSame($keluhan->getMorphClass(), $lampiran->lampiranable_type);
        $this->assertSame($keluhan->id, $lampiran->lampiranable_id);

        // Pencatat (relawan) boleh membuka foto lewat Policy induk; muncul juga di show & index.
        $this->get("/api/v1/lampiran/{$id}")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->getJson("/api/v1/keluhan/{$keluhan->id}")->assertJsonCount(1, 'data.lampiran');
        $this->getJson('/api/v1/keluhan')->assertJsonCount(1, 'data.0.lampiran');
    }

    public function test_log_produksi_dan_mutu_menerima_lampiran_ids_pada_store_dan_update(): void
    {
        $jadwalLog = $this->jadwal();
        $jadwalMutu = $this->jadwal();

        $this->login(Role::Relawan, null, RelawanUnit::Dapur);
        $a = $this->idLampiran('log-produksi');
        $log = $this->postJson('/api/v1/log-produksi', ['jadwal_produksi_id' => $jadwalLog->id, 'realisasi_porsi' => 900, 'lampiran_ids' => [$a]])
            ->assertCreated()->assertJsonPath('data.lampiran.0.id', $a)->assertJsonMissingPath('data.foto_path');
        $b = $this->idLampiran('log-produksi');
        $this->patchJson('/api/v1/log-produksi/'.$log->json('data.id'), ['lampiran_ids' => [$b]])
            ->assertOk()->assertJsonCount(2, 'data.lampiran')->assertJsonPath('data.lampiran.1.id', $b);
        $this->assertSame(2, Lampiran::query()->where('lampiranable_type', (new LogProduksi)->getMorphClass())->count());

        $this->login(Role::AhliGizi);
        $c = $this->idLampiran('mutu');
        $m = $this->postJson('/api/v1/mutu', $this->mutu($jadwalMutu, ['lampiran_ids' => [$c]]))
            ->assertCreated()->assertJsonPath('data.lampiran.0.id', $c)->assertJsonPath('data.lampiran.0.url', "/api/v1/lampiran/{$c}");
        $d = $this->idLampiran('mutu');
        $this->patchJson('/api/v1/mutu/'.$m->json('data.id'), ['lampiran_ids' => [$d]])->assertOk()->assertJsonCount(2, 'data.lampiran');
        $this->assertSame(PemeriksaanMutu::class, Lampiran::query()->findOrFail($d)->lampiranable_type);
    }

    public function test_lampiran_orang_lain_ditolak_dan_tetap_belum_terikat(): void
    {
        $lain = $this->login(Role::Relawan, null, RelawanUnit::Dapur);
        $milikLain = $this->idLampiran('keluhan');

        $saya = $this->login(Role::Relawan, null, RelawanUnit::Dapur);
        $this->assertNotSame($lain->id, $saya->id);

        $this->postJson('/api/v1/keluhan', $this->keluhan(['lampiran_ids' => [$milikLain]]))
            ->assertUnprocessable()->assertJsonValidationErrors(['lampiran_ids']);

        $this->assertSame(0, FeedbackKeluhan::query()->count());
        $this->assertNull(Lampiran::query()->findOrFail($milikLain)->lampiranable_type);

        // Id tidak ada pesan sama (tidak membocorkan keberadaan id).
        $a = $this->postJson('/api/v1/keluhan', $this->keluhan(['lampiran_ids' => [999999]]))->assertUnprocessable()->json('errors.lampiran_ids');
        $b = $this->postJson('/api/v1/keluhan', $this->keluhan(['lampiran_ids' => [$milikLain]]))->assertUnprocessable()->json('errors.lampiran_ids');
        $this->assertSame($a, $b);
    }

    public function test_lampiran_sppg_lain_ditolak(): void
    {
        // Fixture dibuat sebelum login (BelongsToSppg memaksa sppg_id = SPPG pengguna login).
        $relawanB = $this->user(Role::Relawan, $this->sppgB, RelawanUnit::Dapur);
        $admin = $this->user(Role::Admin);
        $relawanA = $this->user(Role::Relawan, $this->sppgA, RelawanUnit::Dapur);

        Sanctum::actingAs($relawanB);
        $dariB = $this->idLampiran('keluhan');

        Sanctum::actingAs($admin);
        $idAdminB = $this->idLampiran('keluhan', ['sppg_id' => $this->sppgB->id]);

        // Pengguna SPPG A tidak dapat memakai lampiran SPPG B.
        Sanctum::actingAs($relawanA);
        $this->postJson('/api/v1/keluhan', $this->keluhan(['lampiran_ids' => [$dariB]]))->assertUnprocessable()->assertJsonValidationErrors(['lampiran_ids']);

        // Admin mengunggah untuk SPPG B lalu membuat keluhan SPPG A: SPPG tidak sama -> ditolak.
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/keluhan', $this->keluhan(['sppg_id' => $this->sppgA->id, 'lampiran_ids' => [$idAdminB]]))
            ->assertUnprocessable()->assertJsonValidationErrors(['lampiran_ids']);
        // SPPG sama -> berhasil.
        $this->postJson('/api/v1/keluhan', $this->keluhan(['sppg_id' => $this->sppgB->id, 'lampiran_ids' => [$idAdminB]]))
            ->assertCreated()->assertJsonCount(1, 'data.lampiran');
    }

    public function test_ikatan_ganda_ditolak(): void
    {
        $this->login(Role::Relawan, null, RelawanUnit::Dapur);
        $id = $this->idLampiran('keluhan');

        $this->postJson('/api/v1/keluhan', $this->keluhan(['lampiran_ids' => [$id]]))->assertCreated();

        // Sudah terikat ke keluhan pertama: dipakai ulang di keluhan kedua -> 422 dan keluhan kedua tidak dibuat.
        $this->postJson('/api/v1/keluhan', $this->keluhan(['lampiran_ids' => [$id]]))->assertUnprocessable()->assertJsonValidationErrors(['lampiran_ids']);
        $this->assertSame(1, FeedbackKeluhan::query()->count());

        // Id yang sama dua kali dalam satu permintaan juga ditolak.
        $baru = $this->idLampiran('keluhan');
        $this->postJson('/api/v1/keluhan', $this->keluhan(['lampiran_ids' => [$baru, $baru]]))->assertUnprocessable()->assertJsonValidationErrors(['lampiran_ids.1']);
        $this->assertNull(Lampiran::query()->findOrFail($baru)->lampiranable_type);
    }

    public function test_kategori_dan_modul_harus_sesuai(): void
    {
        $this->login(Role::Relawan, null, RelawanUnit::Dapur);

        $this->unggah('keluhan', ['kategori' => 'nota'])->assertUnprocessable()->assertJsonValidationErrors(['kategori']);

        // Lampiran modul log-produksi tidak boleh dipakai pada keluhan.
        $salahModul = $this->idLampiran('log-produksi');
        $this->postJson('/api/v1/keluhan', $this->keluhan(['lampiran_ids' => [$salahModul]]))->assertUnprocessable()->assertJsonValidationErrors(['lampiran_ids']);

        // Kategori salah pada data lama (dipaksa lewat DB) ditolak saat diikat.
        $id = $this->idLampiran('keluhan');
        Lampiran::query()->whereKey($id)->update(['kategori' => 'nota']);
        $this->postJson('/api/v1/keluhan', $this->keluhan(['lampiran_ids' => [$id]]))->assertUnprocessable()->assertJsonValidationErrors(['lampiran_ids']);
    }

    public function test_batas_jumlah_lampiran(): void
    {
        $admin = $this->user(Role::Admin);
        $this->login(Role::Relawan, null, RelawanUnit::Dapur);

        $enam = [];
        for ($i = 0; $i < 6; $i++) {
            $enam[] = $this->idLampiran('keluhan');
        }

        $this->postJson('/api/v1/keluhan', $this->keluhan(['lampiran_ids' => $enam]))->assertUnprocessable()->assertJsonValidationErrors(['lampiran_ids']);
        $this->postJson('/api/v1/keluhan', $this->keluhan(['lampiran_ids' => array_slice($enam, 0, 5)]))->assertCreated()->assertJsonCount(5, 'data.lampiran');

        // Penambahan lewat update: total (ada + baru) tetap maksimal 5.
        Sanctum::actingAs($admin);
        $keluhan = FeedbackKeluhan::query()->firstOrFail();
        $tambahan = $this->idLampiran('keluhan', ['sppg_id' => $this->sppgA->id]);
        $this->patchJson("/api/v1/keluhan/{$keluhan->id}", ['lampiran_ids' => [$tambahan]])->assertUnprocessable()->assertJsonValidationErrors(['lampiran_ids']);
        $this->assertNull(Lampiran::query()->findOrFail($tambahan)->lampiranable_type);

        // Mode terikat (lampiranable_id) juga menghormati batas.
        $this->post('/api/v1/lampiran', ['modul' => 'keluhan', 'lampiranable_id' => $keluhan->id, 'berkas' => $this->fotoJpeg()], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['lampiranable_id']);
    }

    public function test_hak_unggah_tertunda_mengikuti_matriks_peran(): void
    {
        // Akuntan: tidak punya hak keluhan/mutu/log-produksi.
        $this->login(Role::Akuntan);
        foreach (['keluhan', 'mutu', 'log-produksi'] as $modul) {
            $this->unggah($modul)->assertForbidden();
        }

        // Relawan distribusi tidak boleh log-produksi (unit dapur), ahli gizi tidak punya C/U log-produksi.
        $this->login(Role::Relawan, null, RelawanUnit::Distribusi);
        $this->unggah('log-produksi')->assertForbidden();
        $this->unggah('mutu')->assertForbidden();
        $this->login(Role::KepalaSppg);
        $this->unggah('mutu')->assertForbidden();          // mutu: kepala hanya R
        $this->unggah('keluhan')->assertCreated();          // keluhan: kepala RU
        $this->unggah('log-produksi')->assertCreated();     // log-produksi: kepala RUD
        $this->login(Role::AhliGizi);
        $this->unggah('mutu')->assertCreated();

        // Modul tanpa mode tertunda wajib lampiranable_id.
        $this->unggah('bukti-distribusi')->assertUnprocessable()->assertJsonValidationErrors(['lampiranable_id']);

        // Admin wajib menyebut sppg_id.
        $this->login(Role::Admin);
        $this->unggah('keluhan')->assertUnprocessable()->assertJsonValidationErrors(['sppg_id']);

        $this->app['auth']->forgetGuards();
        $this->unggah('keluhan')->assertUnauthorized();
    }

    public function test_lampiran_belum_terikat_hanya_dapat_dibaca_pengunggah(): void
    {
        $kepala = $this->user(Role::KepalaSppg);
        $relawanB = $this->user(Role::Relawan, $this->sppgB, RelawanUnit::Dapur);
        $this->login(Role::Relawan, null, RelawanUnit::Dapur);
        $id = $this->idLampiran('keluhan');
        $this->get("/api/v1/lampiran/{$id}")->assertOk();

        Sanctum::actingAs($kepala);
        $this->get("/api/v1/lampiran/{$id}")->assertNotFound();

        Sanctum::actingAs($relawanB);
        $this->get("/api/v1/lampiran/{$id}")->assertNotFound();
    }

    public function test_lampiran_terikat_mengikuti_policy_record_induk(): void
    {
        $kepalaA = $this->user(Role::KepalaSppg);
        $kepalaB = $this->user(Role::KepalaSppg, $this->sppgB);
        $lainnya = $this->user(Role::Relawan, $this->sppgA, RelawanUnit::Dapur);
        $admin = $this->user(Role::Admin);
        $pencatat = $this->login(Role::Relawan, null, RelawanUnit::Dapur);
        $id = $this->idLampiran('keluhan');
        $keluhanId = $this->postJson('/api/v1/keluhan', $this->keluhan(['lampiran_ids' => [$id]]))->assertCreated()->json('data.id');

        // Kepala SPPG A boleh (R keluhan), relawan lain hanya keluhan miliknya -> 403, SPPG B -> 404.
        Sanctum::actingAs($kepalaA);
        $this->get("/api/v1/lampiran/{$id}")->assertOk();

        Sanctum::actingAs($lainnya);
        $this->assertNotSame($pencatat->id, $lainnya->id);
        $this->get("/api/v1/lampiran/{$id}")->assertForbidden();

        Sanctum::actingAs($kepalaB);
        $this->get("/api/v1/lampiran/{$id}")->assertNotFound();

        // Keluhan terhapus lunak -> lampiran tidak disajikan.
        Sanctum::actingAs($admin);
        $this->deleteJson("/api/v1/keluhan/{$keluhanId}")->assertNoContent();
        $this->get("/api/v1/lampiran/{$id}")->assertNotFound();
    }

    public function test_kepala_menambah_foto_pada_keluhan_lewat_update(): void
    {
        $this->login(Role::Relawan, null, RelawanUnit::Dapur);
        $keluhanId = $this->postJson('/api/v1/keluhan', $this->keluhan())->assertCreated()->assertJsonCount(0, 'data.lampiran')->json('data.id');

        $this->login(Role::KepalaSppg);
        $id = $this->idLampiran('keluhan');
        $this->patchJson("/api/v1/keluhan/{$keluhanId}", ['status' => 'diproses', 'lampiran_ids' => [$id]])
            ->assertOk()->assertJsonPath('data.status', 'diproses')->assertJsonPath('data.lampiran.0.id', $id);
    }

    public function test_gagal_simpan_tidak_mengikat_lampiran(): void
    {
        $this->login(Role::Relawan, null, RelawanUnit::Dapur);
        $id = $this->idLampiran('keluhan');

        // Keluhan kritis tanpa batch -> 422 sebelum lampiran diikat.
        $this->postJson('/api/v1/keluhan', $this->keluhan(['kategori' => 'makanan_basi', 'lampiran_ids' => [$id]]))->assertUnprocessable();
        $this->assertNull(Lampiran::query()->findOrFail($id)->lampiranable_type);

        // Setelah diperbaiki, id yang sama dapat dipakai.
        $this->postJson('/api/v1/keluhan', $this->keluhan(['kategori' => 'makanan_basi', 'batch_tidak_diketahui' => true, 'lampiran_ids' => [$id]]))->assertCreated();
    }

    public function test_mode_terikat_lama_tetap_berfungsi_dan_kategori_default_foto(): void
    {
        $this->login(Role::Relawan, null, RelawanUnit::Dapur);
        $keluhanId = $this->postJson('/api/v1/keluhan', $this->keluhan())->json('data.id');

        $this->post('/api/v1/lampiran', ['modul' => 'keluhan', 'lampiranable_id' => $keluhanId, 'berkas' => $this->fotoJpeg()], ['Accept' => 'application/json'])
            ->assertForbidden(); // relawan tidak punya hak U keluhan (CR)

        $this->login(Role::KepalaSppg);
        $this->post('/api/v1/lampiran', ['modul' => 'keluhan', 'lampiranable_id' => $keluhanId, 'berkas' => $this->fotoJpeg()], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.kategori', 'foto');
        $this->getJson("/api/v1/keluhan/{$keluhanId}")->assertJsonCount(1, 'data.lampiran');
    }

    public function test_unggahan_tertunda_kedaluwarsa_dibersihkan_terjadwal(): void
    {
        $this->login(Role::Relawan, null, RelawanUnit::Distribusi);
        $yatim = Lampiran::query()->findOrFail($this->idLampiran('keluhan'));
        $terikat = $this->idLampiran('keluhan');
        $this->postJson('/api/v1/keluhan', $this->keluhan(['lampiran_ids' => [$terikat]]))->assertCreated();

        $this->travel(25)->hours();
        $baru = Lampiran::query()->findOrFail($this->idLampiran('keluhan'));

        $this->artisan('sigap:bersihkan-lampiran-tertunda')->assertSuccessful();

        $this->assertNull(Lampiran::query()->withoutGlobalScopes()->find($yatim->id));
        Storage::disk('uploads')->assertMissing($yatim->path);
        $this->assertNotNull(Lampiran::query()->withoutGlobalScopes()->find($terikat));
        $this->assertNotNull(Lampiran::query()->withoutGlobalScopes()->find($baru->id));
        Storage::disk('uploads')->assertExists($baru->path);
    }
}
