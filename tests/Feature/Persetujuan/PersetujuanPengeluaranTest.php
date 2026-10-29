<?php

namespace Tests\Feature\Persetujuan;

use App\Events\AnggaranMencapai100;
use App\Events\AnggaranMencapai80;
use App\Events\PengeluaranDiajukan;
use App\Models\AuditLog;
use App\Models\Pengeluaran;
use App\Models\PersetujuanPengeluaran;
use App\Models\Scopes\SppgScope;
use App\Services\Keuangan\BudgetService;
use App\Services\Rekap\DailySummaryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\Feature\Pembelian\PembelianTestCase;

/** Persetujuan pengeluaran non-menu oleh investor (R-06b-2): alur, hak, versi/409, anggaran, ambang, audit. */
class PersetujuanPengeluaranTest extends PembelianTestCase
{
    /** @param array<string, mixed> $o @return array<string, mixed> */
    private function bodyKeluar(array $o = []): array
    {
        return array_merge(['tanggal' => now()->toDateString(), 'kategori' => 'energi', 'uraian' => 'Gas elpiji 12 kg', 'nominal' => 750_000], $o);
    }

    /** @param array<string, mixed> $o */
    private function catatKeluar(array $o = []): int
    {
        return (int) $this->sebagai($this->akuntanA)->postJson('/api/v1/keuangan/pengeluaran', $this->bodyKeluar($o))->assertCreated()->json('data.id');
    }

    private function putuskan(int $id, array $body, ?int $sppg = null)
    {
        return $this->sebagai($this->investorAB, $sppg ?? $this->sppgA)->postJson("/api/v1/keuangan/pengeluaran/{$id}/keputusan", $body);
    }

    private function raw(int $id): Pengeluaran
    {
        return Pengeluaran::withoutGlobalScope(SppgScope::class)->withTrashed()->findOrFail($id);
    }

    // ------------------------------------------------------------------ alur dasar

    public function test_pengeluaran_baru_diajukan_belum_dihitung_dan_event_terpancar_tanpa_uraian(): void
    {
        Event::fake([PengeluaranDiajukan::class]);
        $this->pencairan($this->sppgA, 10_000_000);

        $res = $this->sebagai($this->akuntanA)->postJson('/api/v1/keuangan/pengeluaran', $this->bodyKeluar())->assertCreated()
            ->assertJsonPath('data.status_persetujuan', 'diajukan')
            ->assertJsonPath('data.versi', 1)
            ->assertJsonPath('data.melebihi_anggaran', false)
            ->assertJsonCount(1, 'data.riwayat_persetujuan')
            ->assertJsonPath('data.riwayat_persetujuan.0.status', 'diajukan')
            ->assertJsonPath('data.riwayat_persetujuan.0.nominal', 750_000)
            ->assertJsonPath('data.riwayat_persetujuan.0.ambang_persetujuan', 0);

        $id = (int) $res->json('data.id');
        Event::assertDispatchedTimes(PengeluaranDiajukan::class, 1);
        Event::assertDispatched(PengeluaranDiajukan::class, fn (PengeluaranDiajukan $e) => $e->id === $id && $e->sppgId === $this->sppgA && $e->versi === 1
            && $e->nominal === 750_000 && $e->kategori === 'energi' && $e->anggaran === 10_000_000 && $e->terpakai === 0
            && ! array_key_exists('uraian', $e->toArray()));

        $this->getJson('/api/v1/keuangan/ringkasan')->assertOk()
            ->assertJsonPath('data.terpakai', 0)
            ->assertJsonPath('data.jumlah_pengeluaran', 0)
            ->assertJsonPath('data.menunggu_persetujuan.jumlah', 1)
            ->assertJsonPath('data.menunggu_persetujuan.nominal', 750_000);
    }

    public function test_investor_menyetujui_pengeluaran_dihitung_ke_anggaran_dan_ambang_dipancarkan_saat_disetujui(): void
    {
        $this->pencairan($this->sppgA, 1_000_000);
        $id = $this->catatKeluar(['nominal' => 850_000]);

        Event::fake([AnggaranMencapai80::class, AnggaranMencapai100::class]);
        $this->putuskan($id, ['keputusan' => 'disetujui', 'versi' => 1])->assertOk()
            ->assertJsonPath('data.status_persetujuan', 'disetujui')
            ->assertJsonPath('data.riwayat_persetujuan.0.status', 'disetujui')
            ->assertJsonPath('data.riwayat_persetujuan.0.diputuskan_oleh', $this->investorAB->name);

        Event::assertDispatchedTimes(AnggaranMencapai80::class, 1);
        Event::assertNotDispatched(AnggaranMencapai100::class);

        $this->sebagai($this->akuntanA)->getJson('/api/v1/keuangan/ringkasan')->assertJsonPath('data.terpakai', 850_000)
            ->assertJsonPath('data.menunggu_persetujuan.jumlah', 0)->assertJsonPath('data.per_kategori.2.nominal', 850_000);

        // Pengeluaran kedua melampaui sisa: ditandai saat disetujui, bukan saat dicatat.
        $dua = $this->catatKeluar(['nominal' => 300_000]);
        $this->assertFalse((bool) $this->raw($dua)->melebihi_anggaran);
        $this->putuskan($dua, ['keputusan' => 'disetujui', 'versi' => 1])->assertOk()->assertJsonPath('data.melebihi_anggaran', true);
        Event::assertDispatchedTimes(AnggaranMencapai100::class, 1);
    }

    public function test_investor_menolak_wajib_catatan_dan_tidak_dihitung(): void
    {
        $this->pencairan($this->sppgA, 1_000_000);
        $id = $this->catatKeluar();

        $this->putuskan($id, ['keputusan' => 'ditolak', 'versi' => 1])->assertUnprocessable()->assertJsonValidationErrors('catatan');
        $this->putuskan($id, ['keputusan' => 'ditolak', 'catatan' => 'abcd', 'versi' => 1])->assertUnprocessable()->assertJsonValidationErrors('catatan');
        $this->putuskan($id, ['keputusan' => 'perlu_revisi', 'catatan' => 'Tambahkan nota', 'versi' => 1])->assertUnprocessable()->assertJsonValidationErrors('keputusan');
        $this->putuskan($id, ['keputusan' => 'disetujui'])->assertUnprocessable()->assertJsonValidationErrors('versi');

        $this->putuskan($id, ['keputusan' => 'ditolak', 'catatan' => 'Belum ada nota pembelian', 'versi' => 1])->assertOk()
            ->assertJsonPath('data.status_persetujuan', 'ditolak')
            ->assertJsonPath('data.riwayat_persetujuan.0.catatan_keputusan', 'Belum ada nota pembelian');

        $this->sebagai($this->akuntanA)->getJson('/api/v1/keuangan/ringkasan')->assertJsonPath('data.terpakai', 0)->assertJsonPath('data.menunggu_persetujuan.jumlah', 0);
    }

    public function test_akuntan_mengubah_pengeluaran_memulai_ulang_persetujuan(): void
    {
        Event::fake([PengeluaranDiajukan::class]);
        $this->pencairan($this->sppgA, 5_000_000);
        $id = $this->catatKeluar();
        $this->putuskan($id, ['keputusan' => 'disetujui', 'versi' => 1])->assertOk();
        $this->sebagai($this->akuntanA)->getJson('/api/v1/keuangan/ringkasan')->assertJsonPath('data.terpakai', 750_000);

        $this->patchJson("/api/v1/keuangan/pengeluaran/{$id}", ['nominal' => 900_000])->assertUnprocessable()->assertJsonValidationErrors('versi');
        $this->patchJson("/api/v1/keuangan/pengeluaran/{$id}", ['nominal' => 900_000, 'versi' => 1])->assertOk()
            ->assertJsonPath('data.status_persetujuan', 'diajukan')->assertJsonPath('data.versi', 2)->assertJsonPath('data.nominal', 900_000)
            ->assertJsonCount(2, 'data.riwayat_persetujuan')->assertJsonPath('data.riwayat_persetujuan.1.status', 'diajukan');

        // Disetujui lama tidak lagi dihitung sampai diputuskan ulang; event baru untuk versi 2.
        $this->getJson('/api/v1/keuangan/ringkasan')->assertJsonPath('data.terpakai', 0)->assertJsonPath('data.menunggu_persetujuan.nominal', 900_000);
        Event::assertDispatchedTimes(PengeluaranDiajukan::class, 2);
        Event::assertDispatched(PengeluaranDiajukan::class, fn ($e) => $e->versi === 2 && $e->nominal === 900_000);

        // Keputusan atas versi lama ditolak (basi).
        $this->assertAlasan($this->putuskan($id, ['keputusan' => 'disetujui', 'versi' => 1]), 409, 'versi_tidak_cocok')->assertJsonPath('versi_server', 2);
        $this->putuskan($id, ['keputusan' => 'disetujui', 'versi' => 2])->assertOk();
    }

    public function test_ubah_saat_diajukan_membatalkan_pengajuan_lama_dan_ubah_ditolak_mengajukan_ulang(): void
    {
        $id = $this->catatKeluar();
        $this->sebagai($this->akuntanA)->patchJson("/api/v1/keuangan/pengeluaran/{$id}", ['uraian' => 'Gas elpiji 3 kg', 'versi' => 1])->assertOk();

        $status = PersetujuanPengeluaran::withoutGlobalScope(SppgScope::class)->where('pengeluaran_id', $id)->orderBy('nomor')->pluck('status')->map(fn ($s) => $s->value)->all();
        $this->assertSame(['dibatalkan', 'diajukan'], $status);

        $this->putuskan($id, ['keputusan' => 'ditolak', 'catatan' => 'Tidak sesuai anggaran', 'versi' => 2])->assertOk();
        $this->sebagai($this->akuntanA)->patchJson("/api/v1/keuangan/pengeluaran/{$id}", ['nominal' => 500_000, 'versi' => 2])->assertOk()
            ->assertJsonPath('data.status_persetujuan', 'diajukan')->assertJsonPath('data.versi', 3);
        $this->assertSame(3, PersetujuanPengeluaran::withoutGlobalScope(SppgScope::class)->where('pengeluaran_id', $id)->count());
    }

    public function test_hapus_pengeluaran_membatalkan_pengajuan_menunggu(): void
    {
        $id = $this->catatKeluar();

        $this->sebagai($this->akuntanA)->deleteJson("/api/v1/keuangan/pengeluaran/{$id}")->assertNoContent();

        $this->assertSoftDeleted('pengeluarans', ['id' => $id]);
        $this->assertSame('dibatalkan', PersetujuanPengeluaran::withoutGlobalScope(SppgScope::class)->where('pengeluaran_id', $id)->value('status')->value);
        $this->putuskan($id, ['keputusan' => 'disetujui', 'versi' => 1])->assertNotFound();
    }

    // ------------------------------------------------------------------ hak + IDOR

    public function test_hanya_investor_pemilik_yang_boleh_memutuskan(): void
    {
        $id = $this->catatKeluar();
        $body = ['keputusan' => 'disetujui', 'versi' => 1];

        foreach ([$this->admin, $this->kepalaA, $this->ahliGiziA, $this->akuntanA, $this->stafGudangA, $this->relawanA] as $user) {
            $this->sebagai($user)->postJson("/api/v1/keuangan/pengeluaran/{$id}/keputusan", $body)->assertForbidden();
        }

        // Investor C (bukan pemilik SPPG A): header SPPG A ditolak; header SPPG miliknya -> record tak terlihat (404).
        $this->sebagai($this->investorC, $this->sppgA)->postJson("/api/v1/keuangan/pengeluaran/{$id}/keputusan", $body)->assertForbidden();
        $this->sebagai($this->investorC, $this->sppgC)->postJson("/api/v1/keuangan/pengeluaran/{$id}/keputusan", $body)->assertNotFound();
        // Investor AB yang memilih SPPG B tidak dapat memutuskan pengeluaran SPPG A; tanpa header -> wajib pilih.
        $this->sebagai($this->investorAB, $this->sppgB)->postJson("/api/v1/keuangan/pengeluaran/{$id}/keputusan", $body)->assertNotFound();
        $this->assertAlasan($this->sebagai($this->investorAB)->postJson("/api/v1/keuangan/pengeluaran/{$id}/keputusan", $body), 409, 'sppg_aktif_belum_dipilih');

        // Investor tanpa pivot SPPG A.
        $asing = $this->investorBaru($this->sppgC);
        $this->sebagai($asing, $this->sppgC)->postJson("/api/v1/keuangan/pengeluaran/{$id}/keputusan", $body)->assertNotFound();

        $this->assertSame('diajukan', $this->raw($id)->status_persetujuan->value);
        $this->assertSame(0, DB::table('audit_logs')->where('auditable_type', PersetujuanPengeluaran::class)->where('event', 'updated')->count());
    }

    public function test_investor_hanya_baca_dan_tetap_dapat_melihat_riwayat(): void
    {
        $id = $this->catatKeluar();

        $this->sebagai($this->investorAB, $this->sppgA);
        $this->getJson('/api/v1/keuangan/pengeluaran?status_persetujuan=diajukan')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/keuangan/pengeluaran?status_persetujuan=disetujui')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson("/api/v1/keuangan/pengeluaran/{$id}")->assertOk()->assertJsonCount(1, 'data.riwayat_persetujuan');

        $this->postJson('/api/v1/keuangan/pengeluaran', $this->bodyKeluar())->assertForbidden();
        $this->patchJson("/api/v1/keuangan/pengeluaran/{$id}", ['nominal' => 1, 'versi' => 1])->assertForbidden();
        $this->deleteJson("/api/v1/keuangan/pengeluaran/{$id}")->assertForbidden();

        // SPPG lain tidak terlihat.
        $this->sebagai($this->investorC, $this->sppgC)->getJson("/api/v1/keuangan/pengeluaran/{$id}")->assertNotFound();
        $this->getJson('/api/v1/keuangan/pengeluaran')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_keputusan_ganda_dan_investor_kedua_ditolak_409(): void
    {
        $id = $this->catatKeluar();
        $kedua = $this->investorBaru($this->sppgA);

        $this->putuskan($id, ['keputusan' => 'disetujui', 'versi' => 1])->assertOk();
        $this->assertAlasan($this->putuskan($id, ['keputusan' => 'disetujui', 'versi' => 1]), 409, 'status_tidak_valid');
        $this->assertAlasan($this->sebagai($kedua, $this->sppgA)->postJson("/api/v1/keuangan/pengeluaran/{$id}/keputusan", ['keputusan' => 'ditolak', 'catatan' => 'Berbeda pendapat', 'versi' => 1]), 409, 'status_tidak_valid');

        $this->assertSame('disetujui', $this->raw($id)->status_persetujuan->value);
        $diputuskan = PersetujuanPengeluaran::withoutGlobalScope(SppgScope::class)->where('pengeluaran_id', $id)->whereNotNull('diputuskan_oleh')->count();
        $this->assertSame(1, $diputuskan);
    }

    public function test_pengajuan_dibatalkan_tidak_dapat_diputuskan_dan_imutabel(): void
    {
        $id = $this->catatKeluar();
        $this->sebagai($this->akuntanA)->patchJson("/api/v1/keuangan/pengeluaran/{$id}", ['nominal' => 800_000, 'versi' => 1])->assertOk();
        $lama = PersetujuanPengeluaran::withoutGlobalScope(SppgScope::class)->where('pengeluaran_id', $id)->where('nomor', 1)->firstOrFail();

        $this->assertSame('dibatalkan', $lama->status->value);

        foreach ([fn () => $lama->forceFill(['nominal' => 1])->save(), fn () => $lama->forceFill(['status' => 'diajukan'])->save(), fn () => $lama->delete()] as $aksi) {
            try {
                $aksi();
                $this->fail('Persetujuan pengeluaran harus imutabel.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(750_000.0, (float) $lama->fresh()->getRawOriginal('nominal'));
    }

    // ------------------------------------------------------------------ ambang + konfigurasi

    public function test_ambang_persetujuan_membuat_pengeluaran_kecil_langsung_disetujui(): void
    {
        config(['operasional.keuangan.ambang_persetujuan_pengeluaran' => 1_000_000]);
        $this->pencairan($this->sppgA, 10_000_000);
        Event::fake([PengeluaranDiajukan::class]);

        $kecil = $this->sebagai($this->akuntanA)->postJson('/api/v1/keuangan/pengeluaran', $this->bodyKeluar(['nominal' => 999_999]))->assertCreated()
            ->assertJsonPath('data.status_persetujuan', 'disetujui')->assertJsonCount(0, 'data.riwayat_persetujuan')->json('data.id');
        $besar = $this->postJson('/api/v1/keuangan/pengeluaran', $this->bodyKeluar(['nominal' => 1_000_000]))->assertCreated()
            ->assertJsonPath('data.status_persetujuan', 'diajukan')->assertJsonPath('data.riwayat_persetujuan.0.ambang_persetujuan', 1_000_000)->json('data.id');

        Event::assertDispatchedTimes(PengeluaranDiajukan::class, 1);
        $this->getJson('/api/v1/keuangan/ringkasan')->assertJsonPath('data.terpakai', 999_999);
        $this->assertNotSame($kecil, $besar);

        // Mengubah pengeluaran besar menjadi kecil langsung disetujui dan pengajuan lama dibatalkan.
        $this->patchJson("/api/v1/keuangan/pengeluaran/{$besar}", ['nominal' => 10_000, 'versi' => 1])->assertOk()->assertJsonPath('data.status_persetujuan', 'disetujui');
        $this->assertSame('dibatalkan', PersetujuanPengeluaran::withoutGlobalScope(SppgScope::class)->where('pengeluaran_id', $besar)->value('status')->value);
    }

    public function test_ambang_negatif_dianggap_nol(): void
    {
        config(['operasional.keuangan.ambang_persetujuan_pengeluaran' => -5]);

        $this->assertTrue(app(BudgetService::class)->perluPersetujuan(1));
        $this->assertSame(0, (int) max(0, config('operasional.keuangan.ambang_persetujuan_pengeluaran')));
    }

    public function test_tolak_melebihi_anggaran_membatalkan_persetujuan_dengan_422(): void
    {
        config(['operasional.keuangan.tolak_melebihi_anggaran' => true]);
        $this->pencairan($this->sppgA, 500_000);
        $id = $this->catatKeluar(['nominal' => 750_000]);

        $this->putuskan($id, ['keputusan' => 'disetujui', 'versi' => 1])->assertUnprocessable()->assertJsonValidationErrors('nominal');

        $this->assertSame('diajukan', $this->raw($id)->status_persetujuan->value);
        $this->assertSame('diajukan', PersetujuanPengeluaran::withoutGlobalScope(SppgScope::class)->where('pengeluaran_id', $id)->value('status')->value);
    }

    public function test_rekap_harian_hanya_menghitung_pengeluaran_disetujui(): void
    {
        $this->pencairan($this->sppgA, 2_000_000);
        $a = $this->catatKeluar(['nominal' => 400_000]);
        $this->catatKeluar(['nominal' => 300_000]);
        $this->putuskan($a, ['keputusan' => 'disetujui', 'versi' => 1])->assertOk();

        $this->forgetActor();
        $keuangan = app(DailySummaryService::class)->hitung($this->sppgA, now()->toDateString())['keuangan'];

        $this->assertSame('400000.00', $keuangan['pengeluaran_periode']);
        $this->assertSame('400000.00', $keuangan['pengeluaran_hari_ini']);
        $this->assertSame('1600000.00', $keuangan['sisa_anggaran']);
        $this->assertSame(20.0, $keuangan['serapan_persen']);
    }

    public function test_pengeluaran_baru_default_fail_closed_tanpa_status_dari_klien(): void
    {
        $id = (int) $this->sebagai($this->akuntanA)->postJson('/api/v1/keuangan/pengeluaran', $this->bodyKeluar([
            'status_persetujuan' => 'disetujui', 'versi' => 7, 'melebihi_anggaran' => true, 'pembelian_bahan_id' => 1,
        ]))->assertCreated()->assertJsonPath('data.status_persetujuan', 'diajukan')->json('data.id');

        $p = $this->raw($id);
        $this->assertSame(1, $p->versi);
        $this->assertNull($p->pembelian_bahan_id);
        $this->assertFalse((bool) $p->melebihi_anggaran);
        $this->assertSame('diajukan', (string) DB::table('pengeluarans')->where('id', $id)->value('status_persetujuan'));
    }

    public function test_pengeluaran_bahan_baku_langsung_akuntan_tetap_wajib_investor(): void
    {
        $id = $this->catatKeluar(['kategori' => 'bahan_baku', 'uraian' => 'Belanja sayur']);

        $this->assertSame('diajukan', $this->raw($id)->status_persetujuan->value);
    }

    // ------------------------------------------------------------------ audit

    public function test_audit_pengeluaran_dan_persetujuan_tanpa_teks_bebas(): void
    {
        $id = $this->catatKeluar(['uraian' => 'Bayar Pak Joko 0812-FIKTIF']);
        $this->putuskan($id, ['keputusan' => 'ditolak', 'catatan' => 'Catatan investor rahasia', 'versi' => 1])->assertOk();

        $teks = AuditLog::query()->whereIn('auditable_type', [Pengeluaran::class, PersetujuanPengeluaran::class])->get()
            ->map(fn (AuditLog $a) => json_encode([$a->old_values, $a->new_values]))->implode(' ');

        $this->assertStringNotContainsString('Joko', $teks);
        $this->assertStringNotContainsString('0812', $teks);
        $this->assertStringNotContainsString('rahasia', $teks);
        $this->assertStringContainsString('"status_persetujuan":"ditolak"', $teks);

        $keputusan = AuditLog::query()->where('auditable_type', PersetujuanPengeluaran::class)->where('event', 'updated')->firstOrFail();
        $this->assertSame($this->investorAB->id, (int) $keputusan->user_id);
        $this->assertSame($this->sppgA, (int) $keputusan->sppg_id);
    }

    // ------------------------------------------------------------------ migrasi backfill

    public function test_migrasi_backfill_pengeluaran_lama_menjadi_disetujui_dan_down_aman(): void
    {
        $tanpaModel = fn (string $status) => DB::table('pengeluarans')->insertGetId([
            'sppg_id' => $this->sppgA, 'tanggal' => now()->toDateString(), 'kategori' => 'energi', 'uraian' => 'Data lama fiktif',
            'nominal' => 100_000, 'status_persetujuan' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Mundurkan dua migrasi R-06b-2 (persetujuan_pengeluarans dan kolom pengeluarans) menurut berkasnya, bukan
        // "N terakhir" (migrasi baru di kemudian hari tidak boleh ikut mundur), lalu isi data "lama".
        $this->artisan('migrate:rollback', ['--path' => [
            'database/migrations/2026_10_27_000102_add_persetujuan_to_pengeluarans_table.php',
            'database/migrations/2026_10_27_000103_create_persetujuan_pengeluarans_table.php',
        ], '--force' => true])->assertExitCode(0);
        $this->assertFalse(Schema::hasColumn('pengeluarans', 'status_persetujuan'));

        $lama = DB::table('pengeluarans')->insertGetId([
            'sppg_id' => $this->sppgA, 'tanggal' => now()->toDateString(), 'kategori' => 'energi', 'uraian' => 'Data lama fiktif',
            'nominal' => 100_000, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $terhapus = DB::table('pengeluarans')->insertGetId([
            'sppg_id' => $this->sppgA, 'tanggal' => now()->toDateString(), 'kategori' => 'lainnya', 'uraian' => 'Data lama terhapus fiktif',
            'nominal' => 50_000, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => now(),
        ]);

        $this->artisan('migrate')->assertExitCode(0);

        foreach ([$lama, $terhapus] as $baris) {
            $this->assertSame('disetujui', DB::table('pengeluarans')->where('id', $baris)->value('status_persetujuan'));
            $this->assertSame(1, (int) DB::table('pengeluarans')->where('id', $baris)->value('versi'));
        }

        // Data lain tidak berubah; baris baru setelah migrasi default fail-closed.
        $this->assertSame('Data lama fiktif', DB::table('pengeluarans')->where('id', $lama)->value('uraian'));
        $this->assertSame(100000.0, (float) DB::table('pengeluarans')->where('id', $lama)->value('nominal'));
        $baru = $tanpaModel('diajukan');
        $this->assertSame('diajukan', DB::table('pengeluarans')->where('id', $baru)->value('status_persetujuan'));
    }
}
