<?php

namespace Tests\Feature\Keluhan;

use App\Enums\KategoriKeluhan;
use App\Enums\PrioritasKeluhan;
use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Enums\StatusAiDraft;
use App\Enums\StatusKeluhan;
use App\Events\KeluhanKritis;
use App\Models\AiDraft;
use App\Models\FeedbackKeluhan;
use App\Models\JadwalProduksi;
use App\Models\TitikLayanan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\Support\SkenarioOperasional;
use Tests\TestCase;

class KeluhanTest extends TestCase
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

    /** @param array<string, mixed> $override */
    private function payload(array $override = []): array
    {
        return array_merge(['sumber' => 'guru', 'kategori' => 'rasa', 'deskripsi' => 'Sayur terlalu lembek'], $override);
    }

    public function test_relawan_mencatat_keluhan_dengan_prioritas_otomatis(): void
    {
        $relawan = $this->pengguna(Role::Relawan, $this->sppgA, RelawanUnit::Dapur);
        $this->masukPenuh($relawan);

        foreach (['rasa' => 'rendah', 'kemasan' => 'rendah', 'porsi_kurang' => 'sedang', 'terlambat' => 'sedang'] as $kategori => $prioritas) {
            $this->postJson('/api/v1/keluhan', $this->payload(['kategori' => $kategori, 'sppg_id' => $this->sppgB]))
                ->assertCreated()
                ->assertJsonPath('data.prioritas', $prioritas)
                ->assertJsonPath('data.status', 'baru')
                ->assertJsonPath('data.sppg_id', $this->sppgA)
                ->assertJsonPath('data.dicatat_oleh.id', $relawan->id)
                ->assertJsonPath('data.dieskalasi_pada', null);
        }

        $this->assertSame(4, FeedbackKeluhan::query()->count());
    }

    public function test_keluhan_kritis_dieskalasi_dan_memancarkan_event(): void
    {
        Event::fake([KeluhanKritis::class]);
        $relawan = $this->pengguna(Role::Relawan, $this->sppgA, RelawanUnit::Distribusi);
        $titik = TitikLayanan::factory()->create(['sppg_id' => $this->sppgA, 'nama' => 'SDN Contoh 3']);
        $jadwal = JadwalProduksi::factory()->create(['sppg_id' => $this->sppgA]);
        $this->masukPenuh($relawan);

        foreach (['makanan_basi', 'gejala_sakit'] as $kategori) {
            $this->postJson('/api/v1/keluhan', $this->payload(['kategori' => $kategori, 'titik_layanan_id' => $titik->id, 'jadwal_produksi_id' => $jadwal->id]))
                ->assertCreated()
                ->assertJsonPath('data.prioritas', 'kritis')
                ->assertJsonPath('data.jadwal_produksi_id', $jadwal->id);
        }

        Event::assertDispatchedTimes(KeluhanKritis::class, 2);
        Event::assertDispatched(KeluhanKritis::class, function (KeluhanKritis $e) use ($jadwal, $titik) {
            $p = $e->toArray();

            return $p['sppg_id'] === $this->sppgA && $p['prioritas'] === 'kritis' && $p['kategori'] === 'makanan_basi'
                && $p['jadwal_produksi_id'] === $jadwal->id && $p['titik_layanan']['nama'] === 'SDN Contoh 3' && $titik->id === $p['titik_layanan']['id'];
        });
        $this->assertSame(2, FeedbackKeluhan::query()->whereNotNull('dieskalasi_pada')->count());
    }

    public function test_keluhan_kritis_wajib_batch_bila_diketahui(): void
    {
        Event::fake([KeluhanKritis::class]);
        Carbon::setTestNow(Carbon::parse('2026-11-02 12:00:00', 'Asia/Jakarta'));
        $relawan = $this->pengguna(Role::Relawan, $this->sppgA, RelawanUnit::Dapur);
        $dist = $this->jadwalSiap($this->sppgA, [], ['tanggal_distribusi' => '2026-11-02']);
        $titikTanpaJadwal = TitikLayanan::factory()->create(['sppg_id' => $this->sppgA]);
        $this->masukPenuh($relawan);

        // Tanpa batch dan tanpa penyataan -> 422, event tidak dipancarkan.
        $this->postJson('/api/v1/keluhan', $this->payload(['kategori' => 'makanan_basi']))
            ->assertUnprocessable()->assertJsonValidationErrors('jadwal_produksi_id');
        $this->postJson('/api/v1/keluhan', $this->payload(['kategori' => 'makanan_basi', 'titik_layanan_id' => $titikTanpaJadwal->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('jadwal_produksi_id');
        Event::assertNotDispatched(KeluhanKritis::class);

        // Batch disimpulkan dari jadwal distribusi titik layanan hari ini.
        $this->postJson('/api/v1/keluhan', $this->payload(['kategori' => 'makanan_basi', 'titik_layanan_id' => $dist->titik_layanan_id]))
            ->assertCreated()->assertJsonPath('data.jadwal_produksi_id', $dist->jadwal_produksi_id);

        // Batch memang tidak diketahui -> diizinkan secara eksplisit.
        $this->postJson('/api/v1/keluhan', $this->payload(['kategori' => 'gejala_sakit', 'batch_tidak_diketahui' => true]))
            ->assertCreated()->assertJsonPath('data.jadwal_produksi_id', null);

        Event::assertDispatchedTimes(KeluhanKritis::class, 2);
    }

    public function test_prioritas_input_hanya_dapat_menaikkan(): void
    {
        $relawan = $this->pengguna(Role::Relawan, $this->sppgA, RelawanUnit::Dapur);
        $this->masukPenuh($relawan);

        $this->postJson('/api/v1/keluhan', $this->payload(['kategori' => 'rasa', 'prioritas' => 'tinggi']))->assertCreated()->assertJsonPath('data.prioritas', 'tinggi');
        $this->postJson('/api/v1/keluhan', $this->payload(['kategori' => 'makanan_basi', 'prioritas' => 'rendah', 'batch_tidak_diketahui' => true]))->assertCreated()->assertJsonPath('data.prioritas', 'kritis');
    }

    public function test_validasi_gagal_422(): void
    {
        $relawan = $this->pengguna(Role::Relawan, $this->sppgA, RelawanUnit::Dapur);
        $titikLain = TitikLayanan::factory()->create(['sppg_id' => $this->sppgB]);
        $jadwalLain = JadwalProduksi::factory()->create(['sppg_id' => $this->sppgB]);
        $this->masukPenuh($relawan);

        $this->postJson('/api/v1/keluhan', [])->assertUnprocessable()->assertJsonValidationErrors(['sumber', 'kategori', 'deskripsi']);
        $this->postJson('/api/v1/keluhan', $this->payload(['kategori' => 'bukan_kategori']))->assertUnprocessable()->assertJsonValidationErrors('kategori');
        $this->postJson('/api/v1/keluhan', $this->payload(['sumber' => 'alien']))->assertUnprocessable()->assertJsonValidationErrors('sumber');
        $this->postJson('/api/v1/keluhan', $this->payload(['deskripsi' => str_repeat('a', 2001)]))->assertUnprocessable()->assertJsonValidationErrors('deskripsi');
        $this->postJson('/api/v1/keluhan', $this->payload(['titik_layanan_id' => $titikLain->id]))->assertUnprocessable()->assertJsonValidationErrors('titik_layanan_id');
        $this->postJson('/api/v1/keluhan', $this->payload(['jadwal_produksi_id' => $jadwalLain->id]))->assertUnprocessable()->assertJsonValidationErrors('jadwal_produksi_id');
        $this->assertSame(0, FeedbackKeluhan::query()->withoutGlobalScopes()->count());
    }

    public function test_hak_akses_ditolak_403(): void
    {
        $keluhan = FeedbackKeluhan::factory()->create(['sppg_id' => $this->sppgA]);
        $tanpaHak = [Role::Akuntan, Role::StafGudang];

        foreach ($tanpaHak as $role) {
            $this->masukPenuh($this->pengguna($role, $this->sppgA));
            $this->getJson('/api/v1/keluhan')->assertForbidden();
            $this->getJson("/api/v1/keluhan/{$keluhan->id}")->assertForbidden();
            $this->postJson('/api/v1/keluhan', $this->payload())->assertForbidden();
            $this->patchJson("/api/v1/keluhan/{$keluhan->id}", ['status' => 'diproses'])->assertForbidden();
            $this->deleteJson("/api/v1/keluhan/{$keluhan->id}")->assertForbidden();
        }

        // Kepala dan ahli gizi: RU saja (tidak C, tidak D).
        foreach ([Role::KepalaSppg, Role::AhliGizi] as $role) {
            $this->masukPenuh($this->pengguna($role, $this->sppgA));
            $this->postJson('/api/v1/keluhan', $this->payload())->assertForbidden();
            $this->deleteJson("/api/v1/keluhan/{$keluhan->id}")->assertForbidden();
        }

        // Relawan: CR saja.
        $relawan = $this->pengguna(Role::Relawan, $this->sppgA, RelawanUnit::Dapur);
        $milik = FeedbackKeluhan::factory()->create(['sppg_id' => $this->sppgA, 'dicatat_oleh' => $relawan->id]);
        $this->masukPenuh($relawan);
        $this->patchJson("/api/v1/keluhan/{$milik->id}", ['status' => 'diproses'])->assertForbidden();
        $this->deleteJson("/api/v1/keluhan/{$milik->id}")->assertForbidden();
    }

    public function test_relawan_hanya_melihat_keluhan_yang_ia_catat(): void
    {
        $relawan = $this->pengguna(Role::Relawan, $this->sppgA, RelawanUnit::Distribusi);
        $milik = FeedbackKeluhan::factory()->create(['sppg_id' => $this->sppgA, 'dicatat_oleh' => $relawan->id]);
        $lain = FeedbackKeluhan::factory()->create(['sppg_id' => $this->sppgA]);
        $this->masukPenuh($relawan);

        $this->getJson('/api/v1/keluhan')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $milik->id);
        $this->getJson("/api/v1/keluhan/{$milik->id}")->assertOk();
        $this->getJson("/api/v1/keluhan/{$lain->id}")->assertForbidden();
        $this->getJson('/api/v1/keluhan/ringkasan')->assertOk()->assertJsonPath('data.total', 1);
    }

    public function test_alur_status_dan_catatan_wajib(): void
    {
        $kepala = $this->pengguna(Role::KepalaSppg, $this->sppgA);
        $keluhan = FeedbackKeluhan::factory()->create(['sppg_id' => $this->sppgA]);
        $url = "/api/v1/keluhan/{$keluhan->id}";
        $this->masukPenuh($kepala);

        // baru -> selesai / ditolak langsung tidak valid
        $this->patchJson($url, ['status' => 'selesai', 'tindak_lanjut' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->patchJson($url, ['status' => 'ditolak', 'tindak_lanjut' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->patchJson($url, ['status' => 'tidak_ada'])->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->patchJson($url, ['status' => 'diproses'])->assertOk()
            ->assertJsonPath('data.status', 'diproses')
            ->assertJsonPath('data.ditangani_oleh.id', $kepala->id)
            ->assertJsonPath('data.selesai_pada', null);

        // selesai tanpa catatan -> 422
        $this->patchJson($url, ['status' => 'selesai'])->assertUnprocessable()->assertJsonValidationErrors('tindak_lanjut');
        $this->patchJson($url, ['status' => 'selesai', 'tindak_lanjut' => '  '])->assertUnprocessable()->assertJsonValidationErrors('tindak_lanjut');
        $this->assertSame(StatusKeluhan::Diproses, $keluhan->fresh()->status);

        // catatan dapat disimpan saat masih diproses, lalu selesai memakai catatan tersimpan
        $this->patchJson($url, ['tindak_lanjut' => 'Menu diganti'])->assertOk()->assertJsonPath('data.status', 'diproses');
        $this->patchJson($url, ['status' => 'selesai'])->assertOk()
            ->assertJsonPath('data.status', 'selesai')
            ->assertJsonPath('data.tindak_lanjut', 'Menu diganti');
        $this->assertNotNull($keluhan->fresh()->selesai_pada);

        // selesai bersifat final
        $this->patchJson($url, ['status' => 'diproses'])->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_keluhan_dapat_ditolak_dari_diproses_dengan_catatan(): void
    {
        $ahliGizi = $this->pengguna(Role::AhliGizi, $this->sppgA);
        $keluhan = FeedbackKeluhan::factory()->create(['sppg_id' => $this->sppgA, 'status' => StatusKeluhan::Diproses]);
        $this->masukPenuh($ahliGizi);

        $this->patchJson("/api/v1/keluhan/{$keluhan->id}", ['status' => 'ditolak'])->assertUnprocessable()->assertJsonValidationErrors('tindak_lanjut');
        $this->putJson("/api/v1/keluhan/{$keluhan->id}", ['status' => 'ditolak', 'tindak_lanjut' => 'Bukan dari SPPG ini'])->assertOk()
            ->assertJsonPath('data.status', 'ditolak');

        $this->assertSame(0, FeedbackKeluhan::query()->terbuka()->count(), 'ditolak bukan keluhan terbuka');
    }

    public function test_peran_ru_tidak_dapat_mengubah_field_selain_status_dan_catatan(): void
    {
        $kepala = $this->pengguna(Role::KepalaSppg, $this->sppgA);
        $keluhan = FeedbackKeluhan::factory()->create(['sppg_id' => $this->sppgA, 'deskripsi' => 'Asli']);
        $this->masukPenuh($kepala);

        $this->patchJson("/api/v1/keluhan/{$keluhan->id}", [
            'deskripsi' => 'Diubah', 'kategori' => 'makanan_basi', 'prioritas' => 'kritis', 'sppg_id' => $this->sppgB, 'tindak_lanjut' => 'Dicek',
        ])->assertOk()->assertJsonPath('data.deskripsi', 'Asli')->assertJsonPath('data.prioritas', 'rendah')->assertJsonPath('data.tindak_lanjut', 'Dicek');

        $baru = $keluhan->fresh();
        $this->assertSame(KategoriKeluhan::Rasa, $baru->kategori);
        $this->assertSame($this->sppgA, $baru->sppg_id);
    }

    public function test_admin_crud_lengkap_dan_hapus_lunak(): void
    {
        Event::fake([KeluhanKritis::class]);
        $admin = $this->pengguna(Role::Admin, $this->sppgA);
        $jadwal = JadwalProduksi::factory()->create(['sppg_id' => $this->sppgB]);
        $titikA = TitikLayanan::factory()->create(['sppg_id' => $this->sppgA]);
        $this->masukPenuh($admin);

        // sppg_id wajib untuk admin
        $this->postJson('/api/v1/keluhan', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('sppg_id');
        $id = $this->postJson('/api/v1/keluhan', $this->payload(['sppg_id' => $this->sppgB]))->assertCreated()->assertJsonPath('data.sppg_id', $this->sppgB)->json('data.id');
        // titik dari SPPG lain ditolak untuk sppg_id terpilih
        $this->postJson('/api/v1/keluhan', $this->payload(['sppg_id' => $this->sppgB, 'titik_layanan_id' => $titikA->id]))->assertUnprocessable();

        // ubah kategori menjadi kritis -> prioritas naik, eskalasi, event
        $this->patchJson("/api/v1/keluhan/{$id}", ['kategori' => 'gejala_sakit', 'jadwal_produksi_id' => $jadwal->id, 'deskripsi' => 'Siswa mual'])
            ->assertOk()
            ->assertJsonPath('data.prioritas', 'kritis')
            ->assertJsonPath('data.deskripsi', 'Siswa mual')
            ->assertJsonPath('data.sppg_id', $this->sppgB);
        Event::assertDispatchedTimes(KeluhanKritis::class, 1);

        // memperbarui lagi tidak mengeskalasi ulang
        $this->patchJson("/api/v1/keluhan/{$id}", ['deskripsi' => 'Siswa mual dan pusing'])->assertOk();
        Event::assertDispatchedTimes(KeluhanKritis::class, 1);

        $this->deleteJson("/api/v1/keluhan/{$id}")->assertNoContent();
        $this->assertSoftDeleted('feedback_keluhans', ['id' => $id]);
        $this->getJson("/api/v1/keluhan/{$id}")->assertNotFound();
    }

    public function test_isolasi_sppg_dan_admin_lintas_sppg(): void
    {
        $kepalaB = $this->pengguna(Role::KepalaSppg, $this->sppgB);
        $admin = $this->pengguna(Role::Admin, $this->sppgA);
        $keluhan = FeedbackKeluhan::factory()->create(['sppg_id' => $this->sppgA]);
        FeedbackKeluhan::factory()->create(['sppg_id' => $this->sppgB]);

        $this->masukPenuh($kepalaB);
        $this->getJson("/api/v1/keluhan/{$keluhan->id}")->assertNotFound();
        $this->patchJson("/api/v1/keluhan/{$keluhan->id}", ['status' => 'diproses'])->assertNotFound();
        $this->deleteJson("/api/v1/keluhan/{$keluhan->id}")->assertNotFound();
        $this->getJson('/api/v1/keluhan')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/keluhan?sppg_id={$this->sppgA}")->assertOk()->assertJsonCount(1, 'data');

        $this->masukPenuh($admin);
        $this->getJson('/api/v1/keluhan')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/v1/keluhan?sppg_id={$this->sppgA}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $keluhan->id);
        $this->getJson("/api/v1/keluhan/{$keluhan->id}")->assertOk();
    }

    public function test_filter_pencarian_rentang_tanggal_dan_paginasi(): void
    {
        $kepala = $this->pengguna(Role::KepalaSppg, $this->sppgA);
        FeedbackKeluhan::factory()->count(3)->create(['sppg_id' => $this->sppgA, 'created_at' => '2026-11-01 08:00:00']);
        FeedbackKeluhan::factory()->kritis()->create(['sppg_id' => $this->sppgA, 'created_at' => '2026-11-03 08:00:00', 'deskripsi' => 'Ayam bau tengik']);
        FeedbackKeluhan::factory()->selesai()->create(['sppg_id' => $this->sppgA, 'created_at' => '2026-11-05 08:00:00']);
        $this->masukPenuh($kepala);

        $this->getJson('/api/v1/keluhan')->assertOk()->assertJsonPath('meta.total', 5);
        $this->getJson('/api/v1/keluhan?status=selesai')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/keluhan?prioritas=kritis')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/keluhan?kategori=makanan_basi')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/keluhan?from=2026-11-02&to=2026-11-04')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.prioritas', 'kritis');
        $this->getJson('/api/v1/keluhan?from=2026-11-03')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/keluhan?q=tengik')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/keluhan?per_page=2&page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.last_page', 3);
        $this->getJson('/api/v1/keluhan?status=ngawur')->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_ringkasan_jumlah_per_status(): void
    {
        $kepala = $this->pengguna(Role::KepalaSppg, $this->sppgA);
        FeedbackKeluhan::factory()->count(2)->create(['sppg_id' => $this->sppgA]);
        FeedbackKeluhan::factory()->create(['sppg_id' => $this->sppgA, 'status' => StatusKeluhan::Diproses]);
        FeedbackKeluhan::factory()->kritis()->create(['sppg_id' => $this->sppgA]);
        FeedbackKeluhan::factory()->selesai()->create(['sppg_id' => $this->sppgA]);
        FeedbackKeluhan::factory()->create(['sppg_id' => $this->sppgA, 'status' => StatusKeluhan::Ditolak, 'tindak_lanjut' => 'x']);
        FeedbackKeluhan::factory()->kritis()->selesai()->create(['sppg_id' => $this->sppgA]);
        FeedbackKeluhan::factory()->create(['sppg_id' => $this->sppgB]);
        $this->masukPenuh($kepala);

        $this->getJson('/api/v1/keluhan/ringkasan')->assertOk()->assertExactJson(['data' => [
            'total' => 7,
            'per_status' => ['baru' => 3, 'diproses' => 1, 'selesai' => 2, 'ditolak' => 1],
            'per_prioritas' => ['rendah' => 5, 'sedang' => 0, 'tinggi' => 0, 'kritis' => 2],
            'terbuka' => 4,
            'kritis_terbuka' => 1,
        ]]);
    }

    public function test_ai_draft_dikonfirmasi_setelah_store_dan_update(): void
    {
        $relawan = $this->pengguna(Role::Relawan, $this->sppgA, RelawanUnit::Dapur);
        $kepala = $this->pengguna(Role::KepalaSppg, $this->sppgA);
        $draf1 = AiDraft::factory()->create(['sppg_id' => $this->sppgA, 'user_id' => $relawan->id, 'module' => 'keluhan']);
        $drafLain = AiDraft::factory()->create(['sppg_id' => $this->sppgA, 'user_id' => $relawan->id, 'module' => 'pengeluaran']);
        $keluhan = FeedbackKeluhan::factory()->create(['sppg_id' => $this->sppgA]);
        $draf2 = AiDraft::factory()->create(['sppg_id' => $this->sppgA, 'user_id' => $kepala->id, 'module' => 'keluhan', 'action' => 'update', 'target_id' => $keluhan->id]);

        $this->masukPenuh($relawan);
        // draf modul lain ditolak dan tidak ada keluhan yang tersimpan (transaksi dibatalkan)
        $this->postJson('/api/v1/keluhan', $this->payload(['ai_draft_id' => $drafLain->uuid]))->assertUnprocessable()->assertJsonValidationErrors('ai_draft_id');
        $this->assertSame(1, FeedbackKeluhan::query()->count());
        $this->postJson('/api/v1/keluhan', $this->payload(['ai_draft_id' => $draf1->uuid]))->assertCreated();
        $this->assertSame(StatusAiDraft::Confirmed, $draf1->fresh()->status);

        $this->masukPenuh($kepala);
        $this->patchJson("/api/v1/keluhan/{$keluhan->id}", ['status' => 'diproses', 'ai_draft_id' => $draf2->uuid])->assertOk();
        $this->assertSame(StatusAiDraft::Confirmed, $draf2->fresh()->status);
    }

    public function test_skema_kritis_wajib_batch_tidak_mempengaruhi_keluhan_non_kritis(): void
    {
        $relawan = $this->pengguna(Role::Relawan, $this->sppgA, RelawanUnit::Dapur);
        $this->masukPenuh($relawan);

        $this->postJson('/api/v1/keluhan', $this->payload(['kategori' => 'rasa']))->assertCreated()->assertJsonPath('data.jadwal_produksi_id', null);
        $this->assertSame(PrioritasKeluhan::Rendah, FeedbackKeluhan::query()->first()->prioritas);
    }
}
