<?php

namespace Tests\Feature\GrupB;

use App\Enums\JenisMutasi;
use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Enums\StatusAiDraft;
use App\Events\BahanAkanKedaluwarsa;
use App\Events\StokMenipis;
use App\Models\AiDraft;
use App\Models\BahanBaku;
use App\Models\JadwalProduksi;
use App\Models\Menu;
use App\Models\MutasiStok;
use App\Models\Pemasok;
use App\Services\Stock\StockService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

class BahanBakuTest extends GrupBTestCase
{
    private function payload(array $override = []): array
    {
        return array_merge([
            'nama' => 'Beras Premium', 'kode' => 'BB-001', 'kategori' => 'karbohidrat',
            'satuan' => 'kg', 'stok_minimum' => 50,
        ], $override);
    }

    public function test_staf_gudang_crud_dan_soft_delete(): void
    {
        $this->login(Role::StafGudang);

        $id = $this->postJson('/api/v1/bahan-baku', $this->payload(['sppg_id' => $this->sppgB->id]))
            ->assertCreated()
            ->assertJsonPath('data.nama', 'Beras Premium')
            ->assertJsonPath('data.sppg_id', $this->sppgA->id)
            ->assertJsonPath('data.stok', 0)
            ->json('data.id');

        $this->getJson("/api/v1/bahan-baku/{$id}")->assertOk()->assertJsonPath('data.kategori_label', 'Karbohidrat');
        $this->putJson("/api/v1/bahan-baku/{$id}", $this->payload(['nama' => 'Beras Medium']))->assertOk()->assertJsonPath('data.nama', 'Beras Medium');
        $this->patchJson("/api/v1/bahan-baku/{$id}", ['stok_minimum' => 10, 'stok' => 9999])->assertOk()->assertJsonPath('data.stok', 0);
        $this->deleteJson("/api/v1/bahan-baku/{$id}")->assertNoContent();

        $this->assertSoftDeleted('bahan_bakus', ['id' => $id]);
        $this->getJson("/api/v1/bahan-baku/{$id}")->assertNotFound();
    }

    public function test_validasi_gagal_422(): void
    {
        BahanBaku::factory()->create(['sppg_id' => $this->sppgA->id, 'kode' => 'BB-001']);
        $pemasokLain = Pemasok::factory()->create(['sppg_id' => $this->sppgB->id]);
        $this->login(Role::StafGudang);

        $this->postJson('/api/v1/bahan-baku', [])->assertUnprocessable()->assertJsonValidationErrors(['nama', 'kategori', 'satuan']);
        $this->postJson('/api/v1/bahan-baku', $this->payload(['kategori' => 'batu']))->assertUnprocessable()->assertJsonValidationErrors(['kategori']);
        $this->postJson('/api/v1/bahan-baku', $this->payload())->assertUnprocessable()->assertJsonValidationErrors(['kode']);
        $this->postJson('/api/v1/bahan-baku', $this->payload(['kode' => 'X', 'stok_minimum' => -1]))->assertUnprocessable()->assertJsonValidationErrors(['stok_minimum']);
        $this->postJson('/api/v1/bahan-baku', $this->payload(['kode' => 'Y', 'pemasok_id' => $pemasokLain->id]))->assertUnprocessable()->assertJsonValidationErrors(['pemasok_id']);
        $this->postJson('/api/v1/bahan-baku', $this->payload(['kode' => 'Z', 'ai_draft_id' => '00000000-0000-4000-8000-000000000000']))->assertUnprocessable()->assertJsonValidationErrors(['ai_draft_id']);
    }

    public function test_peran_tanpa_hak_403(): void
    {
        $bahan = BahanBaku::factory()->create(['sppg_id' => $this->sppgA->id]);

        $this->login(Role::KepalaSppg);
        $this->getJson('/api/v1/bahan-baku')->assertOk();
        $this->getJson("/api/v1/bahan-baku/{$bahan->id}")->assertOk();
        $this->postJson('/api/v1/bahan-baku', $this->payload())->assertForbidden();
        $this->putJson("/api/v1/bahan-baku/{$bahan->id}", $this->payload())->assertForbidden();
        $this->deleteJson("/api/v1/bahan-baku/{$bahan->id}")->assertForbidden();
        $this->postJson("/api/v1/bahan-baku/{$bahan->id}/mutasi", ['jenis' => 'masuk', 'jumlah' => 1])->assertForbidden();

        $this->login(Role::Akuntan);
        $this->postJson('/api/v1/bahan-baku', $this->payload())->assertForbidden();

        $this->login(Role::Relawan, null, RelawanUnit::Dapur);
        $this->getJson('/api/v1/bahan-baku')->assertForbidden();
        $this->getJson('/api/v1/bahan-baku/stok-menipis')->assertForbidden();
        $this->getJson("/api/v1/bahan-baku/{$bahan->id}/batch")->assertForbidden();
    }

    public function test_tanpa_token_401(): void
    {
        $this->getJson('/api/v1/bahan-baku')->assertUnauthorized();
    }

    public function test_lintas_sppg_404_dan_admin_lintas_sppg(): void
    {
        $milikB = BahanBaku::factory()->create(['sppg_id' => $this->sppgB->id, 'nama' => 'Milik B']);
        BahanBaku::factory()->create(['sppg_id' => $this->sppgA->id, 'nama' => 'Milik A']);

        $this->login(Role::StafGudang);
        $this->getJson("/api/v1/bahan-baku/{$milikB->id}")->assertNotFound();
        $this->putJson("/api/v1/bahan-baku/{$milikB->id}", $this->payload())->assertNotFound();
        $this->postJson("/api/v1/bahan-baku/{$milikB->id}/mutasi", ['jenis' => 'masuk', 'jumlah' => 5])->assertNotFound();
        $this->deleteJson("/api/v1/bahan-baku/{$milikB->id}")->assertNotFound();
        $this->getJson('/api/v1/bahan-baku')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nama', 'Milik A');

        $this->login(Role::Admin);
        $this->getJson('/api/v1/bahan-baku')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/bahan-baku?sppg_id='.$this->sppgB->id)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/bahan-baku/{$milikB->id}")->assertOk();
        $this->postJson('/api/v1/bahan-baku', $this->payload())->assertUnprocessable()->assertJsonValidationErrors(['sppg_id']);
        $this->postJson('/api/v1/bahan-baku', $this->payload(['sppg_id' => $this->sppgB->id]))->assertCreated()->assertJsonPath('data.sppg_id', $this->sppgB->id);
    }

    public function test_pencarian_filter_dan_paginasi(): void
    {
        BahanBaku::factory()->create(['sppg_id' => $this->sppgA->id, 'nama' => 'Beras Merah', 'kategori' => 'karbohidrat']);
        BahanBaku::factory()->create(['sppg_id' => $this->sppgA->id, 'nama' => 'Bayam Hijau', 'kategori' => 'sayur']);
        BahanBaku::factory()->count(3)->create(['sppg_id' => $this->sppgA->id, 'kategori' => 'bumbu', 'nama' => 'Garam Dapur']);
        $this->login(Role::AhliGizi);

        $this->getJson('/api/v1/bahan-baku?q=beras')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/bahan-baku?kategori=sayur')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/bahan-baku?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 5)->assertJsonPath('meta.per_page', 2);
        $this->getJson('/api/v1/bahan-baku?per_page=1000')->assertOk()->assertJsonPath('meta.per_page', 100);
    }

    public function test_mutasi_masuk_keluar_penyesuaian_mengubah_saldo(): void
    {
        $pemasok = Pemasok::factory()->create(['sppg_id' => $this->sppgA->id]);
        $bahan = BahanBaku::factory()->create(['sppg_id' => $this->sppgA->id, 'stok' => 0, 'stok_minimum' => 10, 'pemasok_id' => null, 'kedaluwarsa_terdekat' => null]);
        $jadwal = JadwalProduksi::factory()->create(['sppg_id' => $this->sppgA->id]);
        $gudang = $this->login(Role::StafGudang);

        $this->postJson("/api/v1/bahan-baku/{$bahan->id}/mutasi", [
            'jenis' => 'masuk', 'jumlah' => 100.5, 'pemasok_id' => $pemasok->id, 'harga_satuan' => 14500,
            'tanggal_kedaluwarsa' => now()->addDays(30)->toDateString(), 'catatan' => 'Pengiriman pagi',
        ])->assertCreated()
            ->assertJsonPath('data.jenis', 'masuk')
            ->assertJsonPath('data.stok_setelah', 100.5)
            ->assertJsonPath('data.dicatat_oleh', $gudang->id)
            ->assertJsonPath('data.pemasok.id', $pemasok->id)
            ->assertJsonPath('bahan_baku.stok', 100.5);

        $this->postJson("/api/v1/bahan-baku/{$bahan->id}/mutasi", ['jenis' => 'keluar', 'jumlah' => 40.25, 'jadwal_produksi_id' => $jadwal->id])
            ->assertCreated()->assertJsonPath('data.stok_setelah', 60.25);

        $this->postJson("/api/v1/bahan-baku/{$bahan->id}/mutasi", ['jenis' => 'penyesuaian', 'jumlah' => -0.25, 'catatan' => 'Susut timbang'])
            ->assertCreated()->assertJsonPath('data.jumlah', -0.25)->assertJsonPath('data.stok_setelah', 60);

        $this->postJson("/api/v1/bahan-baku/{$bahan->id}/mutasi", ['jenis' => 'penyesuaian', 'jumlah' => 5, 'catatan' => 'Opname lebih'])
            ->assertCreated()->assertJsonPath('data.stok_setelah', 65);

        $this->assertEquals(65, $bahan->fresh()->getRawOriginal('stok'));
        $this->assertSame($pemasok->id, $bahan->fresh()->pemasok_id);
        $this->assertSame(4, MutasiStok::query()->where('bahan_baku_id', $bahan->id)->count());
        $this->getJson("/api/v1/bahan-baku/{$bahan->id}/mutasi?jenis=keluar")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.pencatat', $gudang->name);
    }

    public function test_stok_tidak_boleh_negatif_dan_tidak_berubah(): void
    {
        $bahan = BahanBaku::factory()->create(['sppg_id' => $this->sppgA->id, 'stok' => 10]);
        $this->login(Role::StafGudang);

        $this->postJson("/api/v1/bahan-baku/{$bahan->id}/mutasi", ['jenis' => 'keluar', 'jumlah' => 10.001, 'catatan' => 'Dapur'])
            ->assertUnprocessable()->assertJsonValidationErrors(['jumlah']);
        $this->postJson("/api/v1/bahan-baku/{$bahan->id}/mutasi", ['jenis' => 'penyesuaian', 'jumlah' => -11, 'catatan' => 'Opname'])
            ->assertUnprocessable()->assertJsonValidationErrors(['jumlah']);

        $this->assertEquals(10, $bahan->fresh()->getRawOriginal('stok'));
        $this->assertSame(0, MutasiStok::query()->count());

        // Habis tepat sampai nol diperbolehkan.
        $this->postJson("/api/v1/bahan-baku/{$bahan->id}/mutasi", ['jenis' => 'keluar', 'jumlah' => 10, 'catatan' => 'Habis'])->assertCreated()->assertJsonPath('data.stok_setelah', 0);
    }

    public function test_validasi_mutasi(): void
    {
        $bahan = BahanBaku::factory()->create(['sppg_id' => $this->sppgA->id, 'stok' => 10]);
        $pemasokLain = Pemasok::factory()->create(['sppg_id' => $this->sppgB->id]);
        $this->login(Role::StafGudang);
        $url = "/api/v1/bahan-baku/{$bahan->id}/mutasi";

        $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors(['jenis', 'jumlah']);
        $this->postJson($url, ['jenis' => 'masuk', 'jumlah' => 0])->assertUnprocessable()->assertJsonValidationErrors(['jumlah']);
        $this->postJson($url, ['jenis' => 'masuk', 'jumlah' => -5])->assertUnprocessable()->assertJsonValidationErrors(['jumlah']);
        $this->postJson($url, ['jenis' => 'masuk', 'jumlah' => 1.2345])->assertUnprocessable()->assertJsonValidationErrors(['jumlah']);
        $this->postJson($url, ['jenis' => 'penyesuaian', 'jumlah' => 1])->assertUnprocessable()->assertJsonValidationErrors(['catatan']);
        $this->postJson($url, ['jenis' => 'keluar', 'jumlah' => 1])->assertUnprocessable()->assertJsonValidationErrors(['catatan']);
        $this->postJson($url, ['jenis' => 'masuk', 'jumlah' => 1, 'pemasok_id' => $pemasokLain->id])->assertUnprocessable()->assertJsonValidationErrors(['pemasok_id']);
        $this->postJson($url, ['jenis' => 'masuk', 'jumlah' => 1, 'tanggal_mutasi' => now()->addDay()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors(['tanggal_mutasi']);
        $this->postJson($url, ['jenis' => 'masuk', 'jumlah' => 1, 'tanggal_mutasi' => '2026-10-10', 'tanggal_kedaluwarsa' => '2026-10-01'])->assertUnprocessable()->assertJsonValidationErrors(['tanggal_kedaluwarsa']);
        $this->postJson($url, ['jenis' => 'meminjam', 'jumlah' => 1])->assertUnprocessable()->assertJsonValidationErrors(['jenis']);
    }

    public function test_stok_per_batch_fefo_dan_kedaluwarsa_terdekat(): void
    {
        $bahan = BahanBaku::factory()->create(['sppg_id' => $this->sppgA->id, 'stok' => 0, 'kedaluwarsa_terdekat' => null]);
        $this->login(Role::StafGudang);
        $url = "/api/v1/bahan-baku/{$bahan->id}/mutasi";
        $lambat = now()->addDays(20)->toDateString();
        $cepat = now()->addDays(5)->toDateString();

        $this->postJson($url, ['jenis' => 'masuk', 'jumlah' => 50, 'tanggal_kedaluwarsa' => $lambat])->assertCreated();
        $this->postJson($url, ['jenis' => 'masuk', 'jumlah' => 30, 'tanggal_kedaluwarsa' => $cepat])->assertCreated();
        $this->assertSame($cepat, $bahan->fresh()->kedaluwarsa_terdekat->toDateString());

        // Keluar 30 menghabiskan batch cepat (FEFO), sisa batch lambat 50.
        $this->postJson($url, ['jenis' => 'keluar', 'jumlah' => 30, 'catatan' => 'Masak'])->assertCreated();
        $this->assertSame($lambat, $bahan->fresh()->kedaluwarsa_terdekat->toDateString());

        $this->postJson($url, ['jenis' => 'keluar', 'jumlah' => 10, 'catatan' => 'Masak'])->assertCreated();
        $this->getJson("/api/v1/bahan-baku/{$bahan->id}/batch")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tanggal_kedaluwarsa', $lambat)
            ->assertJsonPath('data.0.jumlah_awal', 50)
            ->assertJsonPath('data.0.sisa', 40)
            ->assertJsonPath('data.0.sisa_hari', 20)
            ->assertJsonPath('stok_tanpa_batch', 0);
    }

    public function test_stok_menipis_dan_kedaluwarsa_dekat(): void
    {
        BahanBaku::factory()->stokMenipis()->create(['sppg_id' => $this->sppgA->id, 'nama' => 'Garam', 'kedaluwarsa_terdekat' => null]);
        BahanBaku::factory()->create(['sppg_id' => $this->sppgA->id, 'nama' => 'Beras', 'stok' => 500, 'stok_minimum' => 10, 'kedaluwarsa_terdekat' => null]);
        BahanBaku::factory()->kedaluwarsa(2)->create(['sppg_id' => $this->sppgA->id, 'nama' => 'Tahu', 'stok' => 20, 'stok_minimum' => 1]);
        BahanBaku::factory()->kedaluwarsa(10)->create(['sppg_id' => $this->sppgA->id, 'nama' => 'Tempe', 'stok' => 20, 'stok_minimum' => 1]);
        BahanBaku::factory()->kedaluwarsa(1)->create(['sppg_id' => $this->sppgA->id, 'nama' => 'Kosong', 'stok' => 0, 'stok_minimum' => 0]);
        BahanBaku::factory()->stokMenipis()->create(['sppg_id' => $this->sppgB->id, 'nama' => 'Milik B']);
        $this->login(Role::KepalaSppg);

        $this->getJson('/api/v1/bahan-baku/stok-menipis')->assertOk()->assertJsonPath('data.0.nama', 'Garam')->assertJsonPath('data.0.stok_menipis', true);
        $this->assertContains('Kosong', collect($this->getJson('/api/v1/bahan-baku/stok-menipis')->json('data'))->pluck('nama')->all());
        $this->getJson('/api/v1/bahan-baku?stok_menipis=1')->assertOk()->assertJsonCount(2, 'data');

        $names = collect($this->getJson('/api/v1/bahan-baku/kedaluwarsa-dekat')->assertOk()->json('data'))->pluck('nama')->all();
        $this->assertSame(['Tahu'], $names);
        $names = collect($this->getJson('/api/v1/bahan-baku/kedaluwarsa-dekat?hari=14')->json('data'))->pluck('nama')->all();
        $this->assertSame(['Tahu', 'Tempe'], $names);
    }

    public function test_hapus_bahan_yang_dipakai_menu_ditolak(): void
    {
        $bahan = BahanBaku::factory()->create(['sppg_id' => $this->sppgA->id]);
        $menu = Menu::factory()->create(['sppg_id' => $this->sppgA->id]);
        $menu->bahanBakus()->attach($bahan->id, ['gram_per_porsi' => 50]);
        $this->login(Role::StafGudang);

        $this->deleteJson("/api/v1/bahan-baku/{$bahan->id}")->assertUnprocessable()->assertJsonValidationErrors(['bahan_baku']);
        $menu->delete();
        $this->deleteJson("/api/v1/bahan-baku/{$bahan->id}")->assertNoContent();
    }

    public function test_draf_ai_dikonfirmasi_setelah_sukses(): void
    {
        $user = $this->user(Role::StafGudang);
        $draf = AiDraft::factory()->create(['sppg_id' => $this->sppgA->id, 'user_id' => $user->id, 'module' => 'bahan-baku']);
        $drafLain = AiDraft::factory()->create(['sppg_id' => $this->sppgA->id, 'module' => 'bahan-baku']);
        $drafKedaluwarsa = AiDraft::factory()->kedaluwarsa()->create(['sppg_id' => $this->sppgA->id, 'user_id' => $user->id, 'module' => 'bahan-baku']);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/bahan-baku', $this->payload(['ai_draft_id' => $drafLain->uuid]))->assertUnprocessable()->assertJsonValidationErrors(['ai_draft_id']);
        $this->postJson('/api/v1/bahan-baku', $this->payload(['ai_draft_id' => $drafKedaluwarsa->uuid]))->assertUnprocessable()->assertJsonValidationErrors(['ai_draft_id']);
        $this->assertSame(0, BahanBaku::query()->count(), 'transaksi dibatalkan bila draf tidak sah');

        $this->postJson('/api/v1/bahan-baku', $this->payload(['ai_draft_id' => $draf->uuid]))->assertCreated();
        $this->assertSame(StatusAiDraft::Confirmed, $draf->fresh()->status);
        $this->assertNotNull($draf->fresh()->confirmed_at);

        // Draf yang sudah dikonfirmasi tidak dapat dipakai ulang.
        $this->postJson('/api/v1/bahan-baku', $this->payload(['kode' => 'BB-9', 'ai_draft_id' => $draf->uuid]))->assertUnprocessable();
    }

    public function test_command_cek_stok_memancarkan_event_sekali_per_hari(): void
    {
        Cache::flush();
        $menipis = BahanBaku::factory()->stokMenipis()->create(['sppg_id' => $this->sppgA->id, 'kedaluwarsa_terdekat' => null]);
        $kedaluwarsa = BahanBaku::factory()->kedaluwarsa(2)->create(['sppg_id' => $this->sppgB->id, 'stok' => 20, 'stok_minimum' => 1]);
        BahanBaku::factory()->create(['sppg_id' => $this->sppgA->id, 'stok' => 500, 'stok_minimum' => 10, 'kedaluwarsa_terdekat' => now()->addDays(60)->toDateString()]);

        Event::fake([StokMenipis::class, BahanAkanKedaluwarsa::class]);

        $this->artisan('sigap:cek-stok')->assertSuccessful();
        $this->artisan('sigap:cek-stok')->assertSuccessful();

        Event::assertDispatchedTimes(StokMenipis::class, 1);
        Event::assertDispatched(StokMenipis::class, fn (StokMenipis $e) => $e->bahanBakuId === $menipis->id && $e->sppgId === $this->sppgA->id);
        Event::assertDispatchedTimes(BahanAkanKedaluwarsa::class, 1);
        Event::assertDispatched(BahanAkanKedaluwarsa::class, fn (BahanAkanKedaluwarsa $e) => $e->bahanBakuId === $kedaluwarsa->id && $e->sisaHari === 2 && $e->sppgId === $this->sppgB->id);

        // Hari berikutnya peringatan boleh muncul lagi.
        $this->travel(1)->days();
        $this->artisan('sigap:cek-stok')->assertSuccessful();
        Event::assertDispatchedTimes(StokMenipis::class, 2);
    }

    public function test_command_terdaftar_di_scheduler(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains($e->command, 'sigap:cek-stok'));

        $this->assertCount(1, $events);
        $this->assertSame('0 6 * * *', $events->first()->expression);
        $this->assertSame('Asia/Jakarta', (string) $events->first()->timezone);
    }

    public function test_mutasi_keluar_tidak_boleh_dua_kali_melebihi_stok_berurutan(): void
    {
        $bahan = BahanBaku::factory()->create(['sppg_id' => $this->sppgA->id, 'stok' => 5]);
        $this->login(Role::StafGudang);

        $service = app(StockService::class);
        $user = auth()->user();
        $service->catat($bahan, JenisMutasi::Keluar, 3, $user, ['catatan' => 'a']);
        $this->expectException(ValidationException::class);
        $service->catat($bahan->fresh(), JenisMutasi::Keluar, 3, $user, ['catatan' => 'b']);
    }
}
