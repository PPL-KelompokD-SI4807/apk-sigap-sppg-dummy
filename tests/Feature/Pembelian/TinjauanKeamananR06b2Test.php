<?php

namespace Tests\Feature\Pembelian;

use App\Enums\JenisInputAi;
use App\Models\AiDraft;
use App\Models\Lampiran;
use App\Models\PembelianBahan;
use App\Models\Pengeluaran;
use App\Models\PersetujuanPengeluaran;
use App\Models\Scopes\SppgScope;
use App\Models\User;
use App\Services\Ai\ActingUser;
use App\Services\Ai\Tools\ToolContext;
use App\Services\Ai\Tools\ToolRegistry;
use App\Services\Keuangan\BudgetService;
use App\Services\Pembelian\PembelianBahanService;
use App\Services\Persetujuan\PersetujuanPengeluaranService;
use App\Services\Storage\PenjagaLampiranInduk;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Perbaikan tinjauan keamanan R-06b-2: M1 (nota pembelian setelah terverifikasi + TOCTOU), M2 (lampiran pada
 * pengeluaran disetujui/hasil pembelian), L1 (payload AiDraft hanya kunci aturan), L2 (kunci sppgs pertama),
 * L4 (throttle ubah pembelian).
 */
class TinjauanKeamananR06b2Test extends PembelianTestCase
{
    /** Unggah lampiran terikat ke record yang ADA (POST /lampiran dengan lampiranable_id). */
    private function lampirkan(User $user, string $modul, int $indukId)
    {
        $this->sebagai($user, $user->isInvestor() ? $this->sppgA : null);

        return $this->post('/api/v1/lampiran', [
            'modul' => $modul, 'lampiranable_id' => $indukId, 'berkas' => $this->fotoJpeg('tambahan.jpg'),
        ], ['Accept' => 'application/json']);
    }

    private function jumlahLampiran(string $tipe, int $id): int
    {
        return Lampiran::query()->withoutGlobalScopes()->where('lampiranable_type', $tipe)->where('lampiranable_id', $id)->count();
    }

    /** @param array<string, mixed> $o */
    private function catatKeluar(array $o = []): int
    {
        return (int) $this->sebagai($this->akuntanA)->postJson('/api/v1/keuangan/pengeluaran', array_merge([
            'tanggal' => now()->toDateString(), 'kategori' => 'energi', 'uraian' => 'Gas elpiji 12 kg', 'nominal' => 750_000,
        ], $o))->assertCreated()->json('data.id');
    }

    private function putuskan(int $id, array $body)
    {
        return $this->sebagai($this->investorAB, $this->sppgA)->postJson("/api/v1/keuangan/pengeluaran/{$id}/keputusan", $body);
    }

    // ------------------------------------------------------------------ M1

    public function test_m1_nota_tidak_dapat_ditambah_ke_pembelian_terverifikasi_dan_tidak_ada_lampiran_baru(): void
    {
        $id = $this->catatPembelian();
        $this->sebagai($this->akuntanA)->postJson("/api/v1/pembelian-bahan/{$id}/verifikasi", ['keputusan' => 'disetujui', 'versi' => 1])->assertOk();
        $sebelum = $this->jumlahLampiran(PembelianBahan::class, $id);

        $this->lampirkan($this->stafGudangA, 'pembelian-bahan', $id)->assertForbidden();
        $this->lampirkan($this->akuntanA, 'pembelian-bahan', $id)->assertForbidden();

        $this->assertSame($sebelum, $this->jumlahLampiran(PembelianBahan::class, $id));
        $this->assertSame(1, $this->pembelianRaw($id)->versi, 'versi tidak berubah setelah penolakan');
    }

    public function test_m1_penjaga_menolak_409_di_bawah_kunci_bila_status_berubah_setelah_policy(): void
    {
        $id = $this->catatPembelian();
        $this->sebagai($this->akuntanA)->postJson("/api/v1/pembelian-bahan/{$id}/verifikasi", ['keputusan' => 'disetujui', 'versi' => 1])->assertOk();

        try {
            app(PenjagaLampiranInduk::class)->kunciDanPeriksa($this->pembelianRaw($id));
            $this->fail('Penjaga harus menolak pembelian terverifikasi.');
        } catch (HttpResponseException $e) {
            $this->assertSame(409, $e->getResponse()->getStatusCode());
            $this->assertSame('pembelian_sudah_diverifikasi', $e->getResponse()->getData(true)['alasan']);
        }
    }

    public function test_m1_toctou_nota_ditambah_setelah_akuntan_membuka_layar_membuat_verifikasi_versi_basi_409(): void
    {
        $id = $this->catatPembelian();
        $versiDilihatAkuntan = (int) $this->sebagai($this->akuntanA)->getJson("/api/v1/pembelian-bahan/{$id}")->assertOk()->json('data.versi');
        $this->assertSame(1, $versiDilihatAkuntan);

        // Staf menambah nota ke pembelian yang masih diajukan (tetap diizinkan) -> himpunan lampiran berubah, versi naik.
        $this->lampirkan($this->stafGudangA, 'pembelian-bahan', $id)->assertCreated();
        $this->assertSame(2, $this->pembelianRaw($id)->versi);
        $this->assertSame(2, $this->jumlahLampiran(PembelianBahan::class, $id));

        $this->assertAlasan(
            $this->sebagai($this->akuntanA)->postJson("/api/v1/pembelian-bahan/{$id}/verifikasi", ['keputusan' => 'disetujui', 'versi' => $versiDilihatAkuntan]),
            409, 'versi_tidak_cocok',
        )->assertJsonPath('versi_server', 2);
        $this->assertSame(0, Pengeluaran::query()->withoutGlobalScopes()->count());

        $this->postJson("/api/v1/pembelian-bahan/{$id}/verifikasi", ['keputusan' => 'disetujui', 'versi' => 2])->assertOk();
    }

    public function test_m1_staf_lain_sppg_dan_peran_tanpa_hak_tidak_dapat_menambah_nota(): void
    {
        $id = $this->catatPembelian();

        $this->lampirkan($this->investorAB, 'pembelian-bahan', $id)->assertForbidden();
        $this->lampirkan($this->relawanA, 'pembelian-bahan', $id)->assertForbidden();
        $this->lampirkan($this->ahliGiziA, 'pembelian-bahan', $id)->assertForbidden();
        $this->assertSame(1, $this->jumlahLampiran(PembelianBahan::class, $id));
        $this->assertSame(1, $this->pembelianRaw($id)->versi);
    }

    // ------------------------------------------------------------------ M2

    public function test_m2_lampiran_ditolak_untuk_pengeluaran_hasil_pembelian(): void
    {
        $id = $this->catatPembelian();
        $pengeluaranId = (int) $this->sebagai($this->akuntanA)->postJson("/api/v1/pembelian-bahan/{$id}/verifikasi", ['keputusan' => 'disetujui', 'versi' => 1])->json('data.pengeluaran_id');

        $this->lampirkan($this->akuntanA, 'keuangan-pengeluaran', $pengeluaranId)->assertForbidden();
        $this->assertSame(0, $this->jumlahLampiran(Pengeluaran::class, $pengeluaranId));
        $this->assertSame(1, Pengeluaran::withoutGlobalScope(SppgScope::class)->findOrFail($pengeluaranId)->versi);
    }

    public function test_m2_lampiran_ditolak_409_untuk_pengeluaran_langsung_yang_sudah_disetujui(): void
    {
        $id = $this->catatKeluar();
        $this->putuskan($id, ['keputusan' => 'disetujui', 'versi' => 1])->assertOk();

        $this->assertAlasan($this->lampirkan($this->akuntanA, 'keuangan-pengeluaran', $id), 409, 'pengeluaran_sudah_disetujui');
        $this->assertSame(0, $this->jumlahLampiran(Pengeluaran::class, $id));

        // Jalur resmi: PATCH mengembalikan ke diajukan, setelah itu lampiran boleh ditambah.
        $this->sebagai($this->akuntanA)->patchJson("/api/v1/keuangan/pengeluaran/{$id}", ['versi' => 1, 'uraian' => 'Gas elpiji 12 kg (lengkap)'])
            ->assertOk()->assertJsonPath('data.status_persetujuan', 'diajukan');
        $this->lampirkan($this->akuntanA, 'keuangan-pengeluaran', $id)->assertCreated();
    }

    public function test_m2_lampiran_pada_pengeluaran_diajukan_menaikkan_versi_dan_keputusan_investor_basi_409(): void
    {
        $id = $this->catatKeluar();

        $this->lampirkan($this->akuntanA, 'keuangan-pengeluaran', $id)->assertCreated();

        $this->assertSame(2, Pengeluaran::withoutGlobalScope(SppgScope::class)->findOrFail($id)->versi);
        $this->assertSame(2, (int) PersetujuanPengeluaran::withoutGlobalScope(SppgScope::class)->where('pengeluaran_id', $id)->where('status', 'diajukan')->value('pengeluaran_versi'));

        $this->assertAlasan($this->putuskan($id, ['keputusan' => 'disetujui', 'versi' => 1]), 409, 'versi_tidak_cocok')->assertJsonPath('versi_server', 2);
        $this->putuskan($id, ['keputusan' => 'disetujui', 'versi' => 2])->assertOk()->assertJsonPath('data.status_persetujuan', 'disetujui');
    }

    // ------------------------------------------------------------------ L1

    public function test_l1_payload_draf_hanya_berisi_kunci_aturan_form_request(): void
    {
        $this->forgetActor();
        $tools = app(ToolRegistry::class)->all();
        $konteks = new ToolContext(null, JenisInputAi::Teks);

        $pembelian = ActingUser::run($this->stafGudangA, fn () => $tools['buat_pembelian_bahan']->execute($this->stafGudangA, [
            'bahan_baku_id' => $this->berasA->id, 'pemasok_id' => $this->pemasokA->id, 'tanggal_beli' => now()->toDateString(),
            'jumlah' => 10, 'harga_satuan' => 12500,
            'total' => 1, 'status' => 'terverifikasi', 'satuan' => 'ton', 'versi' => 9, 'diverifikasi_oleh' => 1, 'pengeluaran_id' => 5,
        ], $konteks));
        $this->assertFalse($pembelian->isError());

        $keluar = ActingUser::run($this->akuntanA, fn () => $tools['buat_pengeluaran']->execute($this->akuntanA, [
            'tanggal' => now()->toDateString(), 'kategori' => 'energi', 'uraian' => 'Gas', 'nominal' => 100_000,
            'status_persetujuan' => 'disetujui', 'melebihi_anggaran' => true, 'versi' => 7, 'pembelian_bahan_id' => 3, 'status' => 'disetujui',
        ], $konteks));
        $this->assertFalse($keluar->isError());

        $drafs = AiDraft::query()->withoutGlobalScopes()->get();
        $this->assertCount(2, $drafs);

        foreach ($drafs as $draf) {
            foreach (['total', 'status', 'satuan', 'status_persetujuan', 'melebihi_anggaran', 'versi', 'diverifikasi_oleh', 'pengeluaran_id', 'pembelian_bahan_id'] as $kunci) {
                $this->assertArrayNotHasKey($kunci, $draf->payload, "ai_drafts.payload memuat {$kunci}");
                $this->assertArrayNotHasKey($kunci, (array) $draf->confidence);
            }
        }

        $this->assertEqualsCanonicalizing(
            ['bahan_baku_id', 'pemasok_id', 'tanggal_beli', 'jumlah', 'harga_satuan'],
            array_keys($drafs->firstWhere('module', 'pembelian-bahan')->payload),
        );
    }

    // ------------------------------------------------------------------ L2

    /** @return list<string> */
    private function rekamKueri(callable $aksi): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $aksi();
        DB::disableQueryLog();

        return array_map(fn (array $q) => strtolower($q['query']), DB::getQueryLog());
    }

    private function assertKueriPertamaMengunciSppg(array $kueri): void
    {
        $this->assertNotEmpty($kueri);
        $this->assertStringContainsString('from "sppgs"', $kueri[0], 'pernyataan pertama transaksi harus mengunci baris sppgs');
    }

    public function test_l2_kunci_sppg_adalah_pernyataan_pertama_pada_jalur_keputusan_dan_verifikasi(): void
    {
        $this->pencairan($this->sppgA, 10_000_000);

        // Keputusan investor: sebelumnya SppgAktif::milik() dibaca lebih dulu (snapshot basi di MySQL RR).
        $id = $this->catatKeluar();
        $pengeluaran = Pengeluaran::withoutGlobalScope(SppgScope::class)->findOrFail($id);
        $kueri = $this->rekamKueri(fn () => app(PersetujuanPengeluaranService::class)->putuskan($this->investorAB, $pengeluaran, 'disetujui', null, 1));
        $this->assertKueriPertamaMengunciSppg($kueri);

        // Verifikasi pembelian.
        $pembelianId = $this->catatPembelian();
        $pembelian = $this->pembelianRaw($pembelianId);
        $kueri = $this->rekamKueri(fn () => app(PembelianBahanService::class)->verifikasi($this->akuntanA, $pembelian, 'disetujui', null, 1));
        $this->assertKueriPertamaMengunciSppg($kueri);

        // Ubah dan hapus pengeluaran (BudgetService).
        $dua = $this->catatKeluar();
        $model = Pengeluaran::withoutGlobalScope(SppgScope::class)->findOrFail($dua);
        $kueri = $this->rekamKueri(fn () => app(BudgetService::class)->ubahPengeluaran($this->akuntanA, $model, ['uraian' => 'Gas baru'], 1));
        $this->assertKueriPertamaMengunciSppg($kueri);

        $model = Pengeluaran::withoutGlobalScope(SppgScope::class)->findOrFail($dua);
        $kueri = $this->rekamKueri(fn () => app(BudgetService::class)->hapusPengeluaran($model));
        $this->assertKueriPertamaMengunciSppg($kueri);
    }

    // ------------------------------------------------------------------ L4

    public function test_l4_ubah_pembelian_memakai_throttle_unggah(): void
    {
        $ditemukan = 0;

        foreach (app('router')->getRoutes()->getRoutes() as $rute) {
            if ($rute->uri() === 'api/v1/pembelian-bahan/{pembelian_bahan}' && array_intersect(['PUT', 'PATCH'], $rute->methods()) !== []) {
                $ditemukan++;
                $this->assertContains('throttle:unggah', $rute->gatherMiddleware());
            }
        }

        $this->assertSame(1, $ditemukan);
    }
}
