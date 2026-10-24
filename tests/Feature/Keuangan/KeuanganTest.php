<?php

namespace Tests\Feature\Keuangan;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Events\AnggaranMelampaui;
use App\Events\AnggaranMencapai100;
use App\Events\AnggaranMencapai80;
use App\Models\AuditLog;
use App\Models\Lampiran;
use App\Models\Pemasok;
use App\Models\PencairanDana;
use App\Models\Pengeluaran;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\GambarUji;
use Tests\Support\SkenarioOperasional;
use Tests\TestCase;

/** PBI 14 Data Keuangan (pencairan dana + pengeluaran operasional + BudgetService). */
class KeuanganTest extends TestCase
{
    use GambarUji, RefreshDatabase, SkenarioOperasional;

    private int $sppgA;

    private int $sppgB;

    private User $akuntan;

    private User $kepala;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('uploads');
        Carbon::setTestNow(Carbon::parse('2026-11-20 09:00:00', 'Asia/Jakarta'));
        $this->sppgA = $this->createSppg('SPPG A');
        $this->sppgB = $this->createSppg('SPPG B');
        $this->akuntan = $this->pengguna(Role::Akuntan, $this->sppgA);
        $this->kepala = $this->pengguna(Role::KepalaSppg, $this->sppgA);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<string, mixed> $o */
    private function pencairan(array $o = []): array
    {
        return array_merge(['periode' => '2026-11', 'sumber_dana' => 'BGN Tahap I', 'nominal' => 100_000_000, 'tanggal_cair' => '2026-11-02', 'no_referensi' => 'REF-FIKTIF-01'], $o);
    }

    /** @param array<string, mixed> $o */
    private function pengeluaran(array $o = []): array
    {
        return array_merge(['tanggal' => '2026-11-10', 'kategori' => 'bahan_baku', 'uraian' => 'Belanja sayur pasar pagi', 'nominal' => 1_500_000], $o);
    }

    private function keluar(int $nominal, array $o = []): int
    {
        return (int) $this->postJson('/api/v1/keuangan/pengeluaran', $this->pengeluaran(['nominal' => $nominal] + $o))->assertCreated()->json('data.id');
    }

    public function test_akuntan_mencatat_pencairan_dan_pengeluaran_dengan_nota(): void
    {
        $pemasok = Pemasok::factory()->create(['sppg_id' => $this->sppgA, 'nama' => 'Tani Fiktif']);
        Sanctum::actingAs($this->akuntan);

        $this->postJson('/api/v1/keuangan/pencairan', $this->pencairan(['sppg_id' => $this->sppgB]))
            ->assertCreated()
            ->assertJsonPath('data.sppg_id', $this->sppgA)
            ->assertJsonPath('data.nominal', 100_000_000)
            ->assertJsonPath('data.periode', '2026-11')
            ->assertJsonPath('data.dicatat_oleh.id', $this->akuntan->id);

        $res = $this->post('/api/v1/keuangan/pengeluaran', $this->pengeluaran([
            'pemasok_id' => $pemasok->id,
            'nota' => $this->fotoJpeg('nota-pasar.jpg'),
        ]), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.nominal', 1_500_000)
            ->assertJsonPath('data.kategori', 'bahan_baku')
            ->assertJsonPath('data.periode', '2026-11')
            ->assertJsonPath('data.melebihi_anggaran', false)
            ->assertJsonPath('data.pemasok.nama', 'Tani Fiktif')
            ->assertJsonPath('data.nota.mime', 'image/jpeg');

        $lampiran = Lampiran::query()->findOrFail($res->json('data.nota.lampiran_id'));
        $this->assertStringStartsWith("uploads/{$this->sppgA}/keuangan-pengeluaran/", $lampiran->path);
        $this->assertSame('nota', $lampiran->kategori);

        Sanctum::actingAs($this->kepala);
        $this->get($res->json('data.nota.url'))->assertOk();
    }

    public function test_validasi_nominal_integer_dan_relasi_milik_sppg(): void
    {
        $pemasokB = Pemasok::factory()->create(['sppg_id' => $this->sppgB]);
        $cairB = PencairanDana::factory()->create(['sppg_id' => $this->sppgB]);
        Sanctum::actingAs($this->akuntan);

        foreach ([0, -5, 1500.5, '1e5', 'abc', 10_000_000_000_000] as $nominal) {
            $this->postJson('/api/v1/keuangan/pengeluaran', $this->pengeluaran(['nominal' => $nominal]))->assertUnprocessable()->assertJsonValidationErrors('nominal');
            $this->postJson('/api/v1/keuangan/pencairan', $this->pencairan(['nominal' => $nominal]))->assertUnprocessable()->assertJsonValidationErrors('nominal');
        }

        $this->postJson('/api/v1/keuangan/pengeluaran', $this->pengeluaran(['kategori' => 'judi']))->assertJsonValidationErrors('kategori');
        $this->postJson('/api/v1/keuangan/pengeluaran', $this->pengeluaran(['tanggal' => '2026-11-21']))->assertJsonValidationErrors('tanggal');
        $this->postJson('/api/v1/keuangan/pengeluaran', $this->pengeluaran(['pemasok_id' => $pemasokB->id]))->assertJsonValidationErrors('pemasok_id');
        $this->postJson('/api/v1/keuangan/pengeluaran', $this->pengeluaran(['pencairan_dana_id' => $cairB->id]))->assertJsonValidationErrors('pencairan_dana_id');
        $this->postJson('/api/v1/keuangan/pengeluaran', ['nominal' => 1000])->assertJsonValidationErrors(['tanggal', 'kategori', 'uraian']);
        $this->postJson('/api/v1/keuangan/pencairan', $this->pencairan(['periode' => '2026-13']))->assertJsonValidationErrors('periode');
        $this->post('/api/v1/keuangan/pengeluaran', $this->pengeluaran(['nota' => $this->berkas('<?php echo 1;', 'nota.jpg')]), ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('nota');

        $this->assertSame(0, Pengeluaran::query()->count());
        $this->assertSame(0, PencairanDana::query()->count());
    }

    public function test_hak_akses_tabel5_dan_isolasi_sppg(): void
    {
        $milikA = Pengeluaran::factory()->create(['sppg_id' => $this->sppgA]);
        $cairA = PencairanDana::factory()->create(['sppg_id' => $this->sppgA]);
        $milikB = Pengeluaran::factory()->create(['sppg_id' => $this->sppgB]);
        $admin = $this->pengguna(Role::Admin, $this->sppgA);
        $akuntanB = $this->pengguna(Role::Akuntan, $this->sppgB);
        $tanpaHak = [
            $this->pengguna(Role::AhliGizi, $this->sppgA),
            $this->pengguna(Role::StafGudang, $this->sppgA),
            $this->pengguna(Role::Relawan, $this->sppgA, RelawanUnit::Distribusi),
        ];

        $this->getJson('/api/v1/keuangan/pengeluaran')->assertUnauthorized();

        foreach ($tanpaHak as $u) {
            Sanctum::actingAs($u);
            $this->getJson('/api/v1/keuangan/pengeluaran')->assertForbidden();
            $this->getJson('/api/v1/keuangan/pencairan')->assertForbidden();
            $this->getJson('/api/v1/keuangan/ringkasan')->assertForbidden();
            $this->getJson("/api/v1/keuangan/pengeluaran/{$milikA->id}")->assertForbidden();
            $this->postJson('/api/v1/keuangan/pengeluaran', $this->pengeluaran())->assertForbidden();
        }

        foreach ([$this->kepala, $admin] as $u) {
            Sanctum::actingAs($u);
            $this->getJson("/api/v1/keuangan/pengeluaran/{$milikA->id}")->assertOk();
            $this->getJson("/api/v1/keuangan/pencairan/{$cairA->id}")->assertOk();
            $this->postJson('/api/v1/keuangan/pengeluaran', $this->pengeluaran())->assertForbidden();
            $this->postJson('/api/v1/keuangan/pencairan', $this->pencairan())->assertForbidden();
            $this->putJson("/api/v1/keuangan/pengeluaran/{$milikA->id}", ['nominal' => 5])->assertForbidden();
            $this->deleteJson("/api/v1/keuangan/pengeluaran/{$milikA->id}")->assertForbidden();
            $this->deleteJson("/api/v1/keuangan/pencairan/{$cairA->id}")->assertForbidden();
        }

        Sanctum::actingAs($this->kepala);
        $this->getJson('/api/v1/keuangan/pengeluaran')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("/api/v1/keuangan/pengeluaran/{$milikB->id}")->assertNotFound();

        Sanctum::actingAs($akuntanB);
        $this->putJson("/api/v1/keuangan/pengeluaran/{$milikA->id}", ['nominal' => 5])->assertNotFound();
        $this->deleteJson("/api/v1/keuangan/pencairan/{$cairA->id}")->assertNotFound();

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/keuangan/pengeluaran')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson("/api/v1/keuangan/pengeluaran?sppg_id={$this->sppgB}")->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $milikB->id);
    }

    public function test_ringkasan_sisa_anggaran_per_kategori_dan_periode(): void
    {
        $admin = $this->pengguna(Role::Admin, $this->sppgA);
        Sanctum::actingAs($this->akuntan);
        $this->postJson('/api/v1/keuangan/pencairan', $this->pencairan())->assertCreated();
        $this->postJson('/api/v1/keuangan/pencairan', $this->pencairan(['periode' => '2026-10', 'tanggal_cair' => '2026-10-01', 'nominal' => 5_000_000]))->assertCreated();
        $this->keluar(30_000_000);
        $this->keluar(10_000_000, ['kategori' => 'energi']);
        $this->keluar(2_500_000, ['tanggal' => '2026-10-15', 'kategori' => 'transportasi']);

        $res = $this->getJson('/api/v1/keuangan/ringkasan')->assertOk()
            ->assertJsonPath('data.sppg_id', $this->sppgA)
            ->assertJsonPath('data.periode', '2026-11')
            ->assertJsonPath('data.anggaran', 100_000_000)
            ->assertJsonPath('data.terpakai', 40_000_000)
            ->assertJsonPath('data.sisa', 60_000_000)
            ->assertJsonPath('data.persen', 40)
            ->assertJsonPath('data.status', 'aman')
            ->assertJsonPath('data.jumlah_pengeluaran', 2);

        $kategori = collect($res->json('data.per_kategori'))->keyBy('kategori');
        $this->assertSame(30_000_000, $kategori['bahan_baku']['nominal']);
        $this->assertSame(75, (int) $kategori['bahan_baku']['persen']);
        $this->assertSame(10_000_000, $kategori['energi']['nominal']);
        $this->assertSame(0, $kategori['peralatan']['nominal']);

        $this->getJson('/api/v1/keuangan/ringkasan?periode=2026-10')->assertOk()
            ->assertJsonPath('data.anggaran', 5_000_000)->assertJsonPath('data.terpakai', 2_500_000)->assertJsonPath('data.persen', 50);
        $this->getJson('/api/v1/keuangan/ringkasan?periode=2026-1')->assertUnprocessable()->assertJsonValidationErrors('periode');
        $this->getJson('/api/v1/keuangan/pengeluaran?periode=2026-10')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/keuangan/pengeluaran?kategori=energi')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/keuangan/pencairan?periode=2026-11')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/keuangan/pengeluaran?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.last_page', 2);

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/keuangan/ringkasan')->assertUnprocessable()->assertJsonValidationErrors('sppg_id');
        $this->getJson("/api/v1/keuangan/ringkasan?sppg_id={$this->sppgA}")->assertOk()->assertJsonPath('data.terpakai', 40_000_000);
        $this->getJson("/api/v1/keuangan/ringkasan?sppg_id={$this->sppgB}")->assertOk()->assertJsonPath('data.anggaran', 0)
            ->assertJsonPath('data.persen', null)->assertJsonPath('data.status', 'belum_ada_anggaran');
        $this->getJson('/api/v1/keuangan/ringkasan?sppg_id=999999')->assertNotFound();
    }

    public function test_event_ambang_80_100_melampaui_sekali_dan_tidak_spam_saat_edit(): void
    {
        Event::fake([AnggaranMencapai80::class, AnggaranMencapai100::class, AnggaranMelampaui::class]);
        Sanctum::actingAs($this->akuntan);
        $this->postJson('/api/v1/keuangan/pencairan', $this->pencairan())->assertCreated();

        $a = $this->keluar(79_000_000);
        Event::assertNotDispatched(AnggaranMencapai80::class);

        $b = $this->keluar(1_000_000);
        Event::assertDispatchedTimes(AnggaranMencapai80::class, 1);
        Event::assertDispatched(AnggaranMencapai80::class, fn (AnggaranMencapai80 $e) => $e->toArray() === [
            'sppg_id' => $this->sppgA, 'periode' => '2026-11', 'ambang' => '80',
            'anggaran' => 100_000_000, 'terpakai' => 80_000_000, 'sisa' => 20_000_000, 'persen' => 80.0,
        ]);

        // Edit turun-naik di sekitar 80% tidak memancarkan ulang.
        $this->putJson("/api/v1/keuangan/pengeluaran/{$b}", ['nominal' => 100_000])->assertOk();
        $this->putJson("/api/v1/keuangan/pengeluaran/{$b}", ['nominal' => 2_000_000])->assertOk();
        Event::assertDispatchedTimes(AnggaranMencapai80::class, 1);

        $this->keluar(19_000_000);
        Event::assertDispatchedTimes(AnggaranMencapai100::class, 1);
        Event::assertNotDispatched(AnggaranMelampaui::class);

        $c = $this->postJson('/api/v1/keuangan/pengeluaran', $this->pengeluaran(['nominal' => 500_000]))->assertCreated()
            ->assertJsonPath('data.melebihi_anggaran', true)->json('data.id');
        Event::assertDispatchedTimes(AnggaranMelampaui::class, 1);
        Event::assertDispatched(AnggaranMelampaui::class, fn ($e) => $e->terpakai === 100_500_000 && $e->persen === 100.5);

        $this->keluar(100_000);
        $this->putJson("/api/v1/keuangan/pengeluaran/{$c}", ['nominal' => 600_000])->assertOk();
        Event::assertDispatchedTimes(AnggaranMencapai100::class, 1);
        Event::assertDispatchedTimes(AnggaranMelampaui::class, 1);
        $this->assertDatabaseCount('peringatan_anggarans', 3);

        // Pencairan tambahan menurunkan serapan -> penanda 80/100/melampaui dipersenjatai ulang.
        $this->postJson('/api/v1/keuangan/pencairan', $this->pencairan(['sumber_dana' => 'BGN Tahap II', 'nominal' => 100_000_000]))->assertCreated();
        $this->assertDatabaseCount('peringatan_anggarans', 0);
        $this->getJson('/api/v1/keuangan/ringkasan')->assertJsonPath('data.status', 'aman')->assertJsonPath('data.sisa', 99_300_000);

        $this->keluar(60_000_000);
        Event::assertDispatchedTimes(AnggaranMencapai80::class, 2);
        $this->assertTrue(Pengeluaran::query()->whereKey($a)->exists());
    }

    public function test_pengeluaran_melebihi_sisa_ditandai_atau_ditolak_sesuai_konfigurasi(): void
    {
        Sanctum::actingAs($this->akuntan);
        // Tanpa pencairan: setiap pengeluaran melebihi sisa (0).
        $id = $this->postJson('/api/v1/keuangan/pengeluaran', $this->pengeluaran())->assertCreated()
            ->assertJsonPath('data.melebihi_anggaran', true)->json('data.id');
        $this->getJson('/api/v1/keuangan/ringkasan')->assertJsonPath('data.status', 'melampaui')->assertJsonPath('data.jumlah_melebihi_anggaran', 1);

        $this->postJson('/api/v1/keuangan/pencairan', $this->pencairan(['nominal' => 2_000_000]))->assertCreated();
        // Edit nominal menghitung ulang penanda (sisa tanpa baris ini = 2 jt).
        $this->putJson("/api/v1/keuangan/pengeluaran/{$id}", ['nominal' => 1_900_000])->assertOk()->assertJsonPath('data.melebihi_anggaran', false);
        $this->getJson('/api/v1/keuangan/pengeluaran?melebihi_anggaran=0')->assertJsonPath('meta.total', 1);

        config(['operasional.keuangan.tolak_melebihi_anggaran' => true]);
        $this->postJson('/api/v1/keuangan/pengeluaran', $this->pengeluaran(['nominal' => 200_000]))->assertUnprocessable()->assertJsonValidationErrors('nominal');
        $this->postJson('/api/v1/keuangan/pengeluaran', $this->pengeluaran(['nominal' => 100_000]))->assertCreated();
        $this->assertSame(2, Pengeluaran::query()->count());
    }

    public function test_ubah_dan_hapus_lunak_tercatat_di_audit(): void
    {
        Sanctum::actingAs($this->akuntan);
        $cair = $this->postJson('/api/v1/keuangan/pencairan', $this->pencairan())->json('data.id');
        $id = $this->keluar(1_500_000);

        $this->putJson("/api/v1/keuangan/pengeluaran/{$id}", ['nominal' => 1_750_000, 'uraian' => 'Belanja sayur (koreksi nota)'])
            ->assertOk()->assertJsonPath('data.nominal', 1_750_000);
        $this->patchJson("/api/v1/keuangan/pencairan/{$cair}", ['nominal' => 120_000_000])->assertOk()->assertJsonPath('data.nominal', 120_000_000);

        $audit = AuditLog::query()->where('auditable_type', Pengeluaran::class)->where('auditable_id', $id)->where('event', 'updated')->firstOrFail();
        $this->assertEquals(1_500_000, (float) $audit->old_values['nominal']);
        $this->assertEquals(1_750_000, (float) $audit->new_values['nominal']);
        $this->assertSame($this->akuntan->id, $audit->user_id);
        $this->assertTrue(AuditLog::query()->where('auditable_type', PencairanDana::class)->where('auditable_id', $cair)->where('event', 'updated')->exists());

        $this->deleteJson("/api/v1/keuangan/pengeluaran/{$id}")->assertNoContent();
        $this->assertSoftDeleted('pengeluarans', ['id' => $id]);
        $this->getJson("/api/v1/keuangan/pengeluaran/{$id}")->assertNotFound();
        $this->getJson('/api/v1/keuangan/ringkasan')->assertJsonPath('data.terpakai', 0);
        $this->assertTrue(AuditLog::query()->where('auditable_type', Pengeluaran::class)->where('auditable_id', $id)->where('event', 'deleted')->exists());

        $this->deleteJson("/api/v1/keuangan/pencairan/{$cair}")->assertNoContent();
        $this->assertSoftDeleted('pencairan_danas', ['id' => $cair]);
    }

    public function test_idempotency_key_pengeluaran(): void
    {
        $akuntan2 = $this->pengguna(Role::Akuntan, $this->sppgA);
        Sanctum::actingAs($this->akuntan);
        $kunci = (string) Str::uuid();

        $a = $this->postJson('/api/v1/keuangan/pengeluaran', $this->pengeluaran(), ['Idempotency-Key' => $kunci])->assertCreated();
        $b = $this->postJson('/api/v1/keuangan/pengeluaran', $this->pengeluaran(), ['Idempotency-Key' => $kunci])->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($a->json('data.id'), $b->json('data.id'));
        $this->assertSame(1, Pengeluaran::query()->count());

        $this->postJson('/api/v1/keuangan/pengeluaran', $this->pengeluaran(['nominal' => 9]), ['Idempotency-Key' => $kunci])
            ->assertUnprocessable()->assertJsonValidationErrors('Idempotency-Key');
        $this->postJson('/api/v1/keuangan/pengeluaran', $this->pengeluaran(), ['Idempotency-Key' => 'bukan-uuid'])
            ->assertUnprocessable()->assertJsonValidationErrors('Idempotency-Key');

        // Respons gagal tidak disimpan: kunci baru, validasi gagal, lalu diperbaiki dengan kunci sama.
        $kunci2 = (string) Str::uuid();
        $this->postJson('/api/v1/keuangan/pengeluaran', $this->pengeluaran(['nominal' => 0]), ['Idempotency-Key' => $kunci2])->assertUnprocessable();
        $this->postJson('/api/v1/keuangan/pengeluaran', $this->pengeluaran(), ['Idempotency-Key' => $kunci2])->assertCreated();

        // Kunci sama milik pengguna lain = permintaan berbeda.
        Sanctum::actingAs($akuntan2);
        $this->postJson('/api/v1/keuangan/pengeluaran', $this->pengeluaran(), ['Idempotency-Key' => $kunci])->assertCreated()
            ->assertHeaderMissing('Idempotent-Replayed');
        $this->postJson('/api/v1/keuangan/pengeluaran', $this->pengeluaran())->assertCreated();
        $this->assertSame(4, Pengeluaran::query()->count());
    }
}
