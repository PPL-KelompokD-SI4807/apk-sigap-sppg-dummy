<?php

namespace Tests\Feature\Pembelian;

use App\Enums\Role;
use App\Events\AnggaranMencapai100;
use App\Events\AnggaranMencapai80;
use App\Models\AuditLog;
use App\Models\HargaPemasok;
use App\Models\Lampiran;
use App\Models\PembelianBahan;
use App\Models\Pengeluaran;
use App\Models\PersetujuanPengeluaran;
use App\Models\User;
use App\Services\Pembelian\PembelianBahanService;
use App\Support\Rbac;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/** Pembelian bahan (R-06b-2): staf gudang mencatat + nota, akuntan verifikasi, RBAC/IDOR/versi/anggaran. */
class PembelianBahanTest extends PembelianTestCase
{
    // ------------------------------------------------------------------ catat

    public function test_staf_gudang_mencatat_pembelian_total_dihitung_server_dan_nota_terikat(): void
    {
        $nota = $this->unggahNota($this->stafGudangA);

        $res = $this->postJson('/api/v1/pembelian-bahan', $this->bodyPembelian([
            'jumlah' => 2.5, 'harga_satuan' => 12500, 'lampiran_ids' => [$nota],
            // Field yang tidak boleh ditentukan klien diabaikan.
            'total' => 1, 'status' => 'terverifikasi', 'satuan' => 'ton', 'sppg_id' => $this->sppgB, 'versi' => 99, 'pengeluaran_id' => 5, 'dicatat_oleh' => $this->akuntanA->id,
        ]))->assertCreated()
            ->assertJsonPath('data.total', 31250)
            ->assertJsonPath('data.satuan', 'kg')
            ->assertJsonPath('data.status', 'diajukan')
            ->assertJsonPath('data.versi', 1)
            ->assertJsonPath('data.sppg_id', $this->sppgA)
            ->assertJsonPath('data.dicatat_oleh.id', $this->stafGudangA->id)
            ->assertJsonPath('data.pengeluaran_id', null)
            ->assertJsonPath('data.bahan_baku.nama', 'Beras Uji')
            ->assertJsonPath('data.pemasok.nama', 'UD Sumber Pangan Fiktif');

        $this->assertCount(1, $res->json('data.lampirans'));
        $p = $this->pembelianRaw((int) $res->json('data.id'));
        $this->assertNotNull($p->nota_path);
        $lampiran = Lampiran::query()->findOrFail($nota);
        $this->assertSame($p->id, (int) $lampiran->lampiranable_id);
        $this->assertSame('nota', $lampiran->kategori);
        $this->assertSame(0, Pengeluaran::query()->withoutGlobalScopes()->count());
    }

    public function test_total_memakai_bcmath_pembulatan_setengah_ke_atas_tanpa_float(): void
    {
        $this->assertSame('134.00', PembelianBahanService::hitungTotal('1.333', '100.50')); // 133,9665
        $this->assertSame('31250.00', PembelianBahanService::hitungTotal('2.5', '12500'));
        $this->assertSame('0.00', PembelianBahanService::hitungTotal('0.001', '100')); // 0,1
        $this->assertSame('1.00', PembelianBahanService::hitungTotal('0.005', '100')); // 0,5 -> 1
        $this->assertSame('3.00', PembelianBahanService::hitungTotal('0.1', '30')); // float 0.1*30 aman
        $this->assertSame('99999999800000000.00', PembelianBahanService::hitungTotal('999999999.000', '99999999.90'));
    }

    public function test_validasi_pembelian_nota_wajib_dan_batas_angka(): void
    {
        $nota = $this->unggahNota($this->stafGudangA);

        $this->postJson('/api/v1/pembelian-bahan', $this->bodyPembelian())->assertUnprocessable()->assertJsonValidationErrors('lampiran_ids');
        $this->postJson('/api/v1/pembelian-bahan', $this->bodyPembelian(['lampiran_ids' => []]))->assertUnprocessable()->assertJsonValidationErrors('lampiran_ids');
        $this->postJson('/api/v1/pembelian-bahan', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['bahan_baku_id', 'pemasok_id', 'tanggal_beli', 'jumlah', 'harga_satuan', 'lampiran_ids']);

        foreach ([0, -1, 'abc', 1.2345, 1_000_000_000] as $jumlah) {
            $this->postJson('/api/v1/pembelian-bahan', $this->bodyPembelian(['jumlah' => $jumlah, 'lampiran_ids' => [$nota]]))
                ->assertUnprocessable()->assertJsonValidationErrors('jumlah');
        }

        foreach ([0, -5, 'x', 10.555, 100_000_000_000] as $harga) {
            $this->postJson('/api/v1/pembelian-bahan', $this->bodyPembelian(['harga_satuan' => $harga, 'lampiran_ids' => [$nota]]))
                ->assertUnprocessable()->assertJsonValidationErrors('harga_satuan');
        }

        $this->postJson('/api/v1/pembelian-bahan', $this->bodyPembelian(['tanggal_beli' => now()->addDay()->toDateString(), 'lampiran_ids' => [$nota]]))
            ->assertUnprocessable()->assertJsonValidationErrors('tanggal_beli');
        $this->postJson('/api/v1/pembelian-bahan', $this->bodyPembelian(['catatan' => str_repeat('a', 501), 'lampiran_ids' => [$nota]]))
            ->assertUnprocessable()->assertJsonValidationErrors('catatan');
        $this->postJson('/api/v1/pembelian-bahan', $this->bodyPembelian(['lampiran_ids' => [$nota, $nota]]))->assertUnprocessable();

        $this->assertSame(0, PembelianBahan::query()->withoutGlobalScopes()->count());
    }

    public function test_bahan_pemasok_dan_nota_harus_milik_sppg_dan_pemasok_aktif(): void
    {
        $nota = $this->unggahNota($this->stafGudangA);
        $bahanB = $this->bahan($this->sppgB);
        $pemasokB = $this->pemasok($this->sppgB);
        $pasif = $this->pemasok($this->sppgA, 'umkm', false);
        $this->sebagai($this->stafGudangA);

        $this->postJson('/api/v1/pembelian-bahan', $this->bodyPembelian(['bahan_baku_id' => $bahanB->id, 'lampiran_ids' => [$nota]]))
            ->assertUnprocessable()->assertJsonValidationErrors('bahan_baku_id');
        $this->postJson('/api/v1/pembelian-bahan', $this->bodyPembelian(['pemasok_id' => $pemasokB->id, 'lampiran_ids' => [$nota]]))
            ->assertUnprocessable()->assertJsonValidationErrors('pemasok_id');
        $this->postJson('/api/v1/pembelian-bahan', $this->bodyPembelian(['pemasok_id' => $pasif->id, 'lampiran_ids' => [$nota]]))
            ->assertUnprocessable()->assertJsonValidationErrors('pemasok_id');

        // Nota milik pengguna lain / modul lain / sudah terikat ditolak dengan pesan generik.
        $notaOrangLain = $this->unggahNotaSebagaiAdminSppg();
        $this->sebagai($this->stafGudangA);
        $this->postJson('/api/v1/pembelian-bahan', $this->bodyPembelian(['lampiran_ids' => [$notaOrangLain]]))
            ->assertUnprocessable()->assertJsonValidationErrors('lampiran_ids');

        $id = $this->postJson('/api/v1/pembelian-bahan', $this->bodyPembelian(['lampiran_ids' => [$nota]]))->assertCreated()->json('data.id');
        $this->postJson('/api/v1/pembelian-bahan', $this->bodyPembelian(['lampiran_ids' => [$nota]]))
            ->assertUnprocessable()->assertJsonValidationErrors('lampiran_ids');
        $this->assertSame(1, PembelianBahan::query()->withoutGlobalScopes()->count());
        $this->assertNotNull($id);
    }

    /** Staf gudang kedua mengunggah nota di SPPG yang sama (milik pengguna lain). */
    private function unggahNotaSebagaiAdminSppg(): int
    {
        $lain = $this->userOf(Role::StafGudang, $this->sppgA);

        return $this->unggahNota($lain);
    }

    // ------------------------------------------------------------------ RBAC / IDOR

    public function test_matriks_hak_pembelian_bahan(): void
    {
        $id = $this->catatPembelian();
        $body = $this->bodyPembelian();

        foreach ([$this->admin, $this->kepalaA, $this->akuntanA] as $boleh) {
            $this->sebagai($boleh, $boleh->isAdmin() ? null : null)->getJson('/api/v1/pembelian-bahan')->assertOk();
            $this->getJson("/api/v1/pembelian-bahan/{$id}")->assertOk();
        }

        foreach ([$this->ahliGiziA, $this->relawanA, $this->investorAB] as $tanpa) {
            $this->sebagai($tanpa, $tanpa->isInvestor() ? $this->sppgA : null)->getJson('/api/v1/pembelian-bahan')->assertForbidden();
            $this->getJson("/api/v1/pembelian-bahan/{$id}")->assertForbidden();
        }

        // Tulis: hanya staf gudang.
        foreach ([$this->admin, $this->kepalaA, $this->ahliGiziA, $this->akuntanA, $this->relawanA, $this->investorAB] as $tanpa) {
            $this->sebagai($tanpa, $tanpa->isInvestor() ? $this->sppgA : null);
            $this->postJson('/api/v1/pembelian-bahan', $body)->assertForbidden();
            $this->patchJson("/api/v1/pembelian-bahan/{$id}", ['versi' => 1, 'jumlah' => 3])->assertForbidden();
        }

        $this->assertSame(1, $this->pembelianRaw($id)->versi);
    }

    public function test_tidak_ada_rute_hapus_pembelian(): void
    {
        $id = $this->catatPembelian();

        $this->sebagai($this->stafGudangA)->deleteJson("/api/v1/pembelian-bahan/{$id}")->assertStatus(405);
        $this->sebagai($this->admin)->deleteJson("/api/v1/pembelian-bahan/{$id}")->assertStatus(405);
    }

    public function test_akses_lintas_sppg_ditolak(): void
    {
        $id = $this->catatPembelian();
        $stafB = $this->userOf(Role::StafGudang, $this->sppgB);
        $akuntanB = $this->userOf(Role::Akuntan, $this->sppgB);

        $this->sebagai($stafB)->getJson("/api/v1/pembelian-bahan/{$id}")->assertNotFound();
        $this->patchJson("/api/v1/pembelian-bahan/{$id}", ['versi' => 1, 'jumlah' => 3])->assertNotFound();
        $this->getJson('/api/v1/pembelian-bahan')->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertSame(1, $this->pembelianRaw($id)->versi);
        $this->sebagai($akuntanB)->postJson("/api/v1/pembelian-bahan/{$id}/verifikasi", ['keputusan' => 'disetujui', 'versi' => 1])->assertNotFound();

        // Header SPPG lain untuk peran satu-SPPG ditolak middleware.
        $this->sebagai($stafB, $this->sppgA)->getJson("/api/v1/pembelian-bahan/{$id}")->assertForbidden();
        $this->assertSame('diajukan', $this->pembelianRaw($id)->status->value);
    }

    public function test_daftar_menyaring_status_bahan_pemasok_tanggal_dan_kata_kunci(): void
    {
        $this->catatPembelian();
        $this->catatPembelian(['tanggal_beli' => now()->subDays(5)->toDateString()]);
        $this->sebagai($this->akuntanA);

        $this->getJson('/api/v1/pembelian-bahan')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/pembelian-bahan?status=terverifikasi')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/pembelian-bahan?status=diajukan')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/pembelian-bahan?bahan_baku_id='.$this->berasA->id)->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/pembelian-bahan?pemasok_id=999999')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/pembelian-bahan?from='.now()->subDay()->toDateString())->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/pembelian-bahan?q=Sumber+Pangan')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/pembelian-bahan?q=tidak-ada')->assertOk()->assertJsonPath('meta.total', 0);
    }

    // ------------------------------------------------------------------ ubah + versi

    public function test_ubah_pembelian_menaikkan_versi_menghitung_ulang_total_dan_wajib_versi(): void
    {
        $id = $this->catatPembelian();
        $this->sebagai($this->stafGudangA);

        $this->patchJson("/api/v1/pembelian-bahan/{$id}", ['jumlah' => 3])->assertUnprocessable()->assertJsonValidationErrors('versi');
        $this->patchJson("/api/v1/pembelian-bahan/{$id}", ['versi' => 1, 'jumlah' => 3, 'harga_satuan' => 14000, 'total' => 5])
            ->assertOk()->assertJsonPath('data.versi', 2)->assertJsonPath('data.total', 42000)->assertJsonPath('data.status', 'diajukan');

        // Ganti bahan menyalin satuan baru dari bahan.
        $telur = $this->bahan($this->sppgA, 'butir', null, 'Telur Uji');
        $this->sebagai($this->stafGudangA);
        $this->patchJson("/api/v1/pembelian-bahan/{$id}", ['versi' => 2, 'bahan_baku_id' => $telur->id])
            ->assertOk()->assertJsonPath('data.satuan', 'butir')->assertJsonPath('data.versi', 3);
    }

    public function test_versi_basi_ditolak_409_dengan_versi_server(): void
    {
        $id = $this->catatPembelian();
        $this->sebagai($this->stafGudangA);
        $this->patchJson("/api/v1/pembelian-bahan/{$id}", ['versi' => 1, 'jumlah' => 3])->assertOk();

        $this->assertAlasan($this->patchJson("/api/v1/pembelian-bahan/{$id}", ['versi' => 1, 'jumlah' => 4]), 409, 'versi_tidak_cocok')
            ->assertJsonPath('versi_server', 2);
        $this->assertEquals(3, (float) $this->pembelianRaw($id)->jumlah);
    }

    public function test_tambah_nota_saat_ubah_dibatasi_maksimal_tiga(): void
    {
        $id = $this->catatPembelian();
        $this->sebagai($this->stafGudangA);
        $n2 = $this->unggahNota($this->stafGudangA);
        $n3 = $this->unggahNota($this->stafGudangA);
        $n4 = $this->unggahNota($this->stafGudangA);

        $this->patchJson("/api/v1/pembelian-bahan/{$id}", ['versi' => 1, 'lampiran_ids' => [$n2, $n3]])->assertOk()->assertJsonCount(3, 'data.lampirans');
        $this->patchJson("/api/v1/pembelian-bahan/{$id}", ['versi' => 2, 'lampiran_ids' => [$n4]])->assertUnprocessable()->assertJsonValidationErrors('lampiran_ids');
        $this->assertSame(2, $this->pembelianRaw($id)->versi);
    }

    // ------------------------------------------------------------------ verifikasi

    public function test_akuntan_menyetujui_membuat_pengeluaran_bahan_baku_disetujui_tanpa_investor(): void
    {
        $this->pencairan($this->sppgA, 1_000_000);
        $id = $this->catatPembelian(['jumlah' => 10, 'harga_satuan' => 12500]);

        $res = $this->sebagai($this->akuntanA)->postJson("/api/v1/pembelian-bahan/{$id}/verifikasi", ['keputusan' => 'disetujui', 'versi' => 1])
            ->assertOk()
            ->assertJsonPath('data.status', 'terverifikasi')
            ->assertJsonPath('data.diverifikasi_oleh.id', $this->akuntanA->id);

        $pengeluaranId = (int) $res->json('data.pengeluaran_id');
        $this->assertGreaterThan(0, $pengeluaranId);

        $p = Pengeluaran::query()->withoutGlobalScopes()->findOrFail($pengeluaranId);
        $this->assertSame('bahan_baku', $p->kategori->value);
        $this->assertSame('disetujui', $p->status_persetujuan->value);
        $this->assertSame($id, (int) $p->pembelian_bahan_id);
        $this->assertSame($this->akuntanA->id, (int) $p->dicatat_oleh);
        $this->assertSame($this->pemasokA->id, (int) $p->pemasok_id);
        $this->assertEquals(125000, (float) $p->getRawOriginal('nominal'));
        $this->assertSame(now()->toDateString(), $p->tanggal->toDateString());
        $this->assertStringContainsString('Beras Uji', $p->uraian);
        $this->assertSame(0, PersetujuanPengeluaran::query()->withoutGlobalScopes()->count());

        // Dihitung ke serapan anggaran, tanpa antre investor.
        $this->getJson('/api/v1/keuangan/ringkasan')->assertOk()
            ->assertJsonPath('data.terpakai', 125000)->assertJsonPath('data.menunggu_persetujuan.jumlah', 0);
    }

    public function test_verifikasi_setuju_memicu_ambang_anggaran(): void
    {
        $this->pencairan($this->sppgA, 150_000);
        Event::fake([AnggaranMencapai80::class, AnggaranMencapai100::class]);
        $id = $this->catatPembelian(['jumlah' => 10, 'harga_satuan' => 15000]); // 150.000 = 100%

        $this->sebagai($this->akuntanA)->postJson("/api/v1/pembelian-bahan/{$id}/verifikasi", ['keputusan' => 'disetujui', 'versi' => 1])->assertOk();

        Event::assertDispatchedTimes(AnggaranMencapai80::class, 1);
        Event::assertDispatchedTimes(AnggaranMencapai100::class, 1);
    }

    public function test_verifikasi_tolak_wajib_alasan_dan_staf_dapat_memperbaiki_lalu_diajukan_ulang(): void
    {
        $id = $this->catatPembelian();
        $this->sebagai($this->akuntanA);

        $this->postJson("/api/v1/pembelian-bahan/{$id}/verifikasi", ['keputusan' => 'ditolak', 'versi' => 1])->assertUnprocessable()->assertJsonValidationErrors('alasan');
        $this->postJson("/api/v1/pembelian-bahan/{$id}/verifikasi", ['keputusan' => 'ditolak', 'alasan' => 'abcd', 'versi' => 1])->assertUnprocessable()->assertJsonValidationErrors('alasan');
        $this->postJson("/api/v1/pembelian-bahan/{$id}/verifikasi", ['keputusan' => 'lain', 'versi' => 1])->assertUnprocessable()->assertJsonValidationErrors('keputusan');
        $this->postJson("/api/v1/pembelian-bahan/{$id}/verifikasi", ['keputusan' => 'disetujui'])->assertUnprocessable()->assertJsonValidationErrors('versi');

        $this->postJson("/api/v1/pembelian-bahan/{$id}/verifikasi", ['keputusan' => 'ditolak', 'alasan' => 'Harga tidak wajar', 'versi' => 1])
            ->assertOk()->assertJsonPath('data.status', 'ditolak')->assertJsonPath('data.alasan_penolakan', 'Harga tidak wajar');
        $this->assertSame(0, Pengeluaran::query()->withoutGlobalScopes()->count());

        // Ditolak -> staf gudang memperbaiki: diajukan lagi, alasan lama dikosongkan, versi naik.
        $this->sebagai($this->stafGudangA)->patchJson("/api/v1/pembelian-bahan/{$id}", ['versi' => 1, 'harga_satuan' => 11000])
            ->assertOk()->assertJsonPath('data.status', 'diajukan')->assertJsonPath('data.alasan_penolakan', null)->assertJsonPath('data.versi', 2);
        $this->sebagai($this->akuntanA)->postJson("/api/v1/pembelian-bahan/{$id}/verifikasi", ['keputusan' => 'disetujui', 'versi' => 2])
            ->assertOk()->assertJsonPath('data.status', 'terverifikasi');
    }

    public function test_hanya_akuntan_pemilik_sppg_yang_boleh_memverifikasi_dan_bukan_pencatat(): void
    {
        $id = $this->catatPembelian();
        $body = ['keputusan' => 'disetujui', 'versi' => 1];

        foreach ([$this->admin, $this->kepalaA, $this->ahliGiziA, $this->stafGudangA, $this->relawanA] as $tanpa) {
            $this->sebagai($tanpa)->postJson("/api/v1/pembelian-bahan/{$id}/verifikasi", $body)->assertForbidden();
        }

        $this->sebagai($this->investorAB, $this->sppgA)->postJson("/api/v1/pembelian-bahan/{$id}/verifikasi", $body)->assertForbidden();

        // Pemisahan tugas: pencatat tidak dapat memverifikasi pembelian yang ia catat sendiri.
        DB::table('pembelian_bahans')->where('id', $id)->update(['dicatat_oleh' => $this->akuntanA->id]);
        $this->sebagai($this->akuntanA)->postJson("/api/v1/pembelian-bahan/{$id}/verifikasi", $body)->assertForbidden();

        $this->assertSame('diajukan', $this->pembelianRaw($id)->status->value);
        $this->assertSame(0, Pengeluaran::query()->withoutGlobalScopes()->count());
    }

    public function test_keputusan_ganda_versi_basi_dan_ubah_setelah_verifikasi_ditolak_409(): void
    {
        $id = $this->catatPembelian();
        $this->sebagai($this->stafGudangA)->patchJson("/api/v1/pembelian-bahan/{$id}", ['versi' => 1, 'jumlah' => 4])->assertOk();

        // Versi lama (1) setelah staf mengubah (2): 409 versi, tidak ada pengeluaran.
        $this->assertAlasan($this->sebagai($this->akuntanA)->postJson("/api/v1/pembelian-bahan/{$id}/verifikasi", ['keputusan' => 'disetujui', 'versi' => 1]), 409, 'versi_tidak_cocok')
            ->assertJsonPath('versi_server', 2);
        $this->assertSame(0, Pengeluaran::query()->withoutGlobalScopes()->count());

        $this->postJson("/api/v1/pembelian-bahan/{$id}/verifikasi", ['keputusan' => 'disetujui', 'versi' => 2])->assertOk();
        $this->assertAlasan($this->postJson("/api/v1/pembelian-bahan/{$id}/verifikasi", ['keputusan' => 'disetujui', 'versi' => 2]), 409, 'status_tidak_valid');
        $this->assertAlasan($this->postJson("/api/v1/pembelian-bahan/{$id}/verifikasi", ['keputusan' => 'ditolak', 'alasan' => 'Terlambat menolak', 'versi' => 2]), 409, 'status_tidak_valid');
        $this->assertSame(1, Pengeluaran::query()->withoutGlobalScopes()->count());

        $this->assertAlasan($this->sebagai($this->stafGudangA)->patchJson("/api/v1/pembelian-bahan/{$id}", ['versi' => 2, 'jumlah' => 9]), 409, 'pembelian_sudah_diverifikasi');
        $this->assertEquals(4, (float) $this->pembelianRaw($id)->jumlah);
    }

    public function test_pengeluaran_hasil_pembelian_tidak_dapat_diubah_atau_dihapus(): void
    {
        $id = $this->catatPembelian();
        $pengeluaranId = (int) $this->sebagai($this->akuntanA)->postJson("/api/v1/pembelian-bahan/{$id}/verifikasi", ['keputusan' => 'disetujui', 'versi' => 1])->json('data.pengeluaran_id');

        $this->assertAlasan($this->patchJson("/api/v1/keuangan/pengeluaran/{$pengeluaranId}", ['versi' => 1, 'nominal' => 1]), 409, 'pengeluaran_dari_pembelian');
        $this->assertAlasan($this->deleteJson("/api/v1/keuangan/pengeluaran/{$pengeluaranId}"), 409, 'pengeluaran_dari_pembelian');
        $this->assertSame(1, Pengeluaran::query()->withoutGlobalScopes()->count());
    }

    public function test_detail_memuat_harga_referensi_informatif(): void
    {
        $id = $this->catatPembelian(['harga_satuan' => 22000]);
        $this->sebagai($this->akuntanA)->getJson("/api/v1/pembelian-bahan/{$id}")->assertOk()->assertJsonPath('data.harga_referensi', null);

        $this->harga($this->pemasokA, $this->berasA, 20000);
        $this->sebagai($this->akuntanA)->getJson("/api/v1/pembelian-bahan/{$id}")->assertOk()
            ->assertJsonPath('data.harga_referensi.harga', 20000)
            ->assertJsonPath('data.harga_referensi.selisih_persen', 10);

        // Daftar tidak membawa harga_referensi (hemat query).
        $this->getJson('/api/v1/pembelian-bahan')->assertOk()->assertJsonMissingPath('data.0.harga_referensi');
        $this->assertSame(1, HargaPemasok::query()->withoutGlobalScopes()->count());
    }

    // ------------------------------------------------------------------ audit + nota privat

    public function test_audit_tanpa_teks_bebas_dan_nota_tidak_tersaji_ke_investor(): void
    {
        $id = $this->catatPembelian(['catatan' => 'Nama sopir Pak Budi 0812-FIKTIF']);
        $this->sebagai($this->akuntanA)->postJson("/api/v1/pembelian-bahan/{$id}/verifikasi", ['keputusan' => 'ditolak', 'alasan' => 'Rahasia alasan penolakan', 'versi' => 1])->assertOk();

        $teks = AuditLog::query()->where('auditable_type', PembelianBahan::class)->where('auditable_id', $id)->get()
            ->map(fn (AuditLog $a) => json_encode([$a->old_values, $a->new_values]))->implode(' ');
        $this->assertNotSame('', $teks);
        $this->assertStringNotContainsString('Pak Budi', $teks);
        $this->assertStringNotContainsString('0812', $teks);
        $this->assertStringNotContainsString('Rahasia', $teks);
        $this->assertStringNotContainsString('nota_path', $teks);
        $this->assertStringContainsString('"status":"ditolak"', $teks);

        $lampiranId = (int) Lampiran::query()->withoutGlobalScopes()->where('lampiranable_id', $id)->value('id');
        $this->sebagai($this->akuntanA)->get("/api/v1/lampiran/{$lampiranId}")->assertOk();
        $this->sebagai($this->investorAB, $this->sppgA)->get("/api/v1/lampiran/{$lampiranId}")->assertForbidden();
        $this->sebagai($this->investorAB, $this->sppgA)->post('/api/v1/lampiran', ['modul' => 'pembelian-bahan', 'berkas' => $this->fotoJpeg()], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_idempotency_key_pada_pembelian(): void
    {
        $nota = $this->unggahNota($this->stafGudangA);
        $kunci = (string) Str::uuid();
        $body = $this->bodyPembelian(['lampiran_ids' => [$nota]]);

        $a = $this->postJson('/api/v1/pembelian-bahan', $body, ['Idempotency-Key' => $kunci])->assertCreated();
        $b = $this->postJson('/api/v1/pembelian-bahan', $body, ['Idempotency-Key' => $kunci])->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($a->json('data.id'), $b->json('data.id'));
        $this->assertSame(1, PembelianBahan::query()->withoutGlobalScopes()->count());
    }

    public function test_pengguna_dan_matriks_tidak_memberi_investor_hak_pembelian(): void
    {
        $this->sebagai($this->investorAB, $this->sppgA)->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('user.baca_saja', true)
            ->assertJsonMissingPath('user.modul.pembelian-bahan');
        $this->sebagai($this->akuntanA)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('user.keputusan', ['pembelian-bahan']);
        $this->assertInstanceOf(User::class, $this->investorAB);
    }

    public function test_total_melebihi_batas_penyimpanan_ditolak_422_bukan_500(): void
    {
        $nota = $this->unggahNota($this->stafGudangA);

        $this->postJson('/api/v1/pembelian-bahan', $this->bodyPembelian(['jumlah' => 999_999_999, 'harga_satuan' => 99_999_999_999, 'lampiran_ids' => [$nota]]))
            ->assertUnprocessable()->assertJsonValidationErrors('harga_satuan');
        $this->assertSame(0, PembelianBahan::query()->withoutGlobalScopes()->count());

        $id = $this->postJson('/api/v1/pembelian-bahan', $this->bodyPembelian(['lampiran_ids' => [$nota]]))->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/pembelian-bahan/{$id}", ['versi' => 1, 'jumlah' => 999_999_999, 'harga_satuan' => 99_999_999_999])
            ->assertUnprocessable()->assertJsonValidationErrors('harga_satuan');
        $this->assertSame(1, $this->pembelianRaw($id)->versi);
    }

    public function test_matriks_rbac_modul_pembelian_bahan_dan_hak_keputusan(): void
    {
        $this->assertSame(
            ['admin' => 'R', 'kepala_sppg' => 'R', 'ahli_gizi' => '', 'akuntan' => 'R', 'staf_gudang' => 'CRU', 'relawan' => '', 'investor' => ''],
            collect(Role::cases())->mapWithKeys(fn (Role $r) => [$r->value => Rbac::cell($r, 'pembelian-bahan')])->all(),
        );

        foreach (Role::cases() as $role) {
            $user = User::factory()->make(['id' => 1, 'role' => $role, 'sppg_id' => 1, 'is_active' => true, 'unit' => null]);
            $this->assertSame($role === Role::Akuntan, Rbac::canDecide($user, 'pembelian-bahan'), "pembelian-bahan/{$role->value}");
            $this->assertSame($role === Role::Investor, Rbac::canDecide($user, 'keuangan'), "keuangan/{$role->value}");
        }

        // Investor tetap baca saja walau memiliki hak keputusan.
        $this->assertTrue(Rbac::readOnly($this->investorAB));
    }
}
