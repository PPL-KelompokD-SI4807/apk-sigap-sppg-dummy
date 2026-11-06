<?php

namespace Tests\Feature\PetaHak;

use App\Enums\StatusPembelianBahan;
use App\Enums\StatusPersetujuanPengeluaran;
use App\Models\BuktiDistribusi;
use App\Models\JadwalDistribusi;
use App\Models\JadwalProduksi;
use App\Models\LogProduksi;
use App\Models\NotifikasiPerjalanan;
use App\Models\PembelianBahan;
use App\Models\Pengeluaran;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * `izin` per record pada resource Jadwal (produksi & distribusi), Log Produksi, Bukti Distribusi, Pembelian Bahan,
 * Pengeluaran, dan Notifikasi Perjalanan: sama dengan Policy (own_same_day relawan, pencatat != pemverifikasi, status
 * final), dan tanpa N+1 (jumlah query daftar tidak tumbuh bersama jumlah baris).
 */
class PetaHakIzinRecordTest extends PetaHakTestCase
{
    private function kemarin(): string
    {
        return now()->subDay()->toDateTimeString();
    }

    /** Ambil `izin` record $id dari detail (show). @return array<string, bool> */
    private function izinDetail(User $user, string $url): array
    {
        return $this->masuk($user)->getJson($url)->assertOk()->json('data.izin');
    }

    // ------------------------------------------------------------------ jadwal

    public function test_izin_jadwal_produksi_per_peran_dan_aturan_milik_sendiri_hari_yang_sama(): void
    {
        $milikHariIni = JadwalProduksi::factory()->create(['sppg_id' => $this->sppgA, 'dibuat_oleh' => $this->relawanA->id]);
        $milikKemarin = JadwalProduksi::factory()->create(['sppg_id' => $this->sppgA, 'dibuat_oleh' => $this->relawanA->id, 'created_at' => $this->kemarin()]);
        $milikKepala = JadwalProduksi::factory()->create(['sppg_id' => $this->sppgA, 'dibuat_oleh' => $this->kepalaA->id]);

        // Relawan dapur: CRU (tanpa D); hanya miliknya sendiri pada hari yang sama.
        $this->assertSame(['ubah' => true, 'hapus' => false], $this->izinDetail($this->relawanA, "/api/v1/jadwal/{$milikHariIni->id}"));
        $this->assertSame(['ubah' => false, 'hapus' => false], $this->izinDetail($this->relawanA, "/api/v1/jadwal/{$milikKemarin->id}"));
        $this->assertSame(['ubah' => false, 'hapus' => false], $this->izinDetail($this->relawanA, "/api/v1/jadwal/{$milikKepala->id}"));

        // Kepala CRUD; ahli gizi CRU; staf gudang dan admin hanya baca.
        $this->assertSame(['ubah' => true, 'hapus' => true], $this->izinDetail($this->kepalaA, "/api/v1/jadwal/{$milikKemarin->id}"));
        $this->assertSame(['ubah' => true, 'hapus' => false], $this->izinDetail($this->ahliGiziA, "/api/v1/jadwal/{$milikKemarin->id}"));
        $this->assertSame(['ubah' => false, 'hapus' => false], $this->izinDetail($this->stafGudangA, "/api/v1/jadwal/{$milikKemarin->id}"));
        $this->assertSame(['ubah' => false, 'hapus' => false], $this->izinDetail($this->admin, "/api/v1/jadwal/{$milikKemarin->id}"));

        // Daftar membawa kunci yang sama.
        $daftar = $this->masuk($this->relawanA)->getJson('/api/v1/jadwal')->assertOk()->json('data');
        $this->assertCount(3, $daftar);

        foreach ($daftar as $baris) {
            $this->assertSame(['ubah', 'hapus'], array_keys($baris['izin']));
        }

        // Relawan distribusi tidak boleh membaca jadwal produksi penuh (403) dan investor tanpa hak.
        $this->masuk($this->relawanDistribusiA)->getJson("/api/v1/jadwal/{$milikHariIni->id}")->assertForbidden();
        $this->masuk($this->investorAB)->getJson("/api/v1/jadwal/{$milikHariIni->id}")->assertForbidden();
    }

    public function test_izin_jadwal_distribusi_relawan_distribusi_milik_sendiri_hari_yang_sama(): void
    {
        $hariIni = JadwalDistribusi::factory()->create(['sppg_id' => $this->sppgA, 'dibuat_oleh' => $this->relawanDistribusiA->id]);
        $kemarin = JadwalDistribusi::factory()->create(['sppg_id' => $this->sppgA, 'dibuat_oleh' => $this->relawanDistribusiA->id, 'created_at' => $this->kemarin()]);
        $kepala = JadwalDistribusi::factory()->create(['sppg_id' => $this->sppgA, 'dibuat_oleh' => $this->kepalaA->id]);

        $this->assertSame(['ubah' => true, 'hapus' => false], $this->izinDetail($this->relawanDistribusiA, "/api/v1/jadwal/distribusi/{$hariIni->id}"));
        $this->assertSame(['ubah' => false, 'hapus' => false], $this->izinDetail($this->relawanDistribusiA, "/api/v1/jadwal/distribusi/{$kemarin->id}"));
        $this->assertSame(['ubah' => false, 'hapus' => false], $this->izinDetail($this->relawanDistribusiA, "/api/v1/jadwal/distribusi/{$kepala->id}"));
        $this->assertSame(['ubah' => true, 'hapus' => true], $this->izinDetail($this->kepalaA, "/api/v1/jadwal/distribusi/{$kemarin->id}"));
        $this->assertSame(['ubah' => false, 'hapus' => false], $this->izinDetail($this->stafGudangA, "/api/v1/jadwal/distribusi/{$kemarin->id}"));

        // Relawan dapur tidak mengelola jadwal distribusi.
        $this->masuk($this->relawanA)->getJson("/api/v1/jadwal/distribusi/{$hariIni->id}")->assertForbidden();
    }

    // ------------------------------------------------------------------ log produksi & bukti distribusi

    public function test_izin_log_produksi(): void
    {
        // Satu log per jadwal produksi (unik).
        $hariIni = LogProduksi::factory()->create(['sppg_id' => $this->sppgA, 'dicatat_oleh' => $this->relawanA->id]);
        $kemarin = LogProduksi::factory()->create(['sppg_id' => $this->sppgA, 'dicatat_oleh' => $this->relawanA->id, 'created_at' => $this->kemarin()]);
        $kepala = LogProduksi::factory()->create(['sppg_id' => $this->sppgA, 'dicatat_oleh' => $this->kepalaA->id]);

        $this->assertSame(['ubah' => true, 'hapus' => false], $this->izinDetail($this->relawanA, "/api/v1/log-produksi/{$hariIni->id}"));
        $this->assertSame(['ubah' => false, 'hapus' => false], $this->izinDetail($this->relawanA, "/api/v1/log-produksi/{$kemarin->id}"));
        $this->assertSame(['ubah' => false, 'hapus' => false], $this->izinDetail($this->relawanA, "/api/v1/log-produksi/{$kepala->id}"));
        $this->assertSame(['ubah' => true, 'hapus' => true], $this->izinDetail($this->kepalaA, "/api/v1/log-produksi/{$kemarin->id}"));
        $this->assertSame(['ubah' => false, 'hapus' => false], $this->izinDetail($this->ahliGiziA, "/api/v1/log-produksi/{$kemarin->id}"));
        $this->assertSame(['ubah' => false, 'hapus' => false], $this->izinDetail($this->admin, "/api/v1/log-produksi/{$kemarin->id}"));

        $this->masuk($this->relawanDistribusiA)->getJson("/api/v1/log-produksi/{$hariIni->id}")->assertForbidden();
    }

    public function test_izin_bukti_distribusi(): void
    {
        $milikHariIni = BuktiDistribusi::factory()->create(['sppg_id' => $this->sppgA, 'dicatat_oleh' => $this->relawanDistribusiA->id]);
        $milikKemarin = BuktiDistribusi::factory()->create(['sppg_id' => $this->sppgA, 'dicatat_oleh' => $this->relawanDistribusiA->id, 'created_at' => $this->kemarin()]);
        $kepala = BuktiDistribusi::factory()->create(['sppg_id' => $this->sppgA, 'dicatat_oleh' => $this->kepalaA->id]);

        $this->assertSame(['ubah' => true, 'hapus' => false], $this->izinDetail($this->relawanDistribusiA, "/api/v1/bukti-distribusi/{$milikHariIni->id}"));
        $this->assertSame(['ubah' => false, 'hapus' => false], $this->izinDetail($this->relawanDistribusiA, "/api/v1/bukti-distribusi/{$milikKemarin->id}"));
        $this->assertSame(['ubah' => true, 'hapus' => true], $this->izinDetail($this->kepalaA, "/api/v1/bukti-distribusi/{$milikKemarin->id}"));
        $this->assertSame(['ubah' => false, 'hapus' => false], $this->izinDetail($this->akuntanA, "/api/v1/bukti-distribusi/{$kepala->id}"));
        $this->assertSame(['ubah' => false, 'hapus' => false], $this->izinDetail($this->admin, "/api/v1/bukti-distribusi/{$kepala->id}"));

        $this->masuk($this->relawanA)->getJson("/api/v1/bukti-distribusi/{$milikHariIni->id}")->assertForbidden();
    }

    // ------------------------------------------------------------------ pembelian & pengeluaran

    public function test_izin_pembelian_bahan_status_final_dan_pemisahan_tugas(): void
    {
        $diajukan = PembelianBahan::factory()->create(['sppg_id' => $this->sppgA, 'dicatat_oleh' => $this->stafGudangA->id]);
        $final = PembelianBahan::factory()->status(StatusPembelianBahan::Terverifikasi)->create(['sppg_id' => $this->sppgA, 'dicatat_oleh' => $this->stafGudangA->id]);
        $dicatatAkuntan = PembelianBahan::factory()->create(['sppg_id' => $this->sppgA, 'dicatat_oleh' => $this->akuntanA->id]);

        $semua = ['ubah' => true, 'hapus' => false, 'lampirkan' => true, 'verifikasi' => false];
        $this->assertSame($semua, $this->izinDetail($this->stafGudangA, "/api/v1/pembelian-bahan/{$diajukan->id}"));
        // Terverifikasi final: tidak dapat diubah/dilampiri, tidak dapat diverifikasi lagi.
        $this->assertSame(['ubah' => false, 'hapus' => false, 'lampirkan' => false, 'verifikasi' => false], $this->izinDetail($this->stafGudangA, "/api/v1/pembelian-bahan/{$final->id}"));

        // Akuntan memverifikasi pembelian orang lain yang diajukan; bukan miliknya sendiri; bukan yang final.
        $this->assertSame(['ubah' => false, 'hapus' => false, 'lampirkan' => false, 'verifikasi' => true], $this->izinDetail($this->akuntanA, "/api/v1/pembelian-bahan/{$diajukan->id}"));
        $this->assertFalse($this->izinDetail($this->akuntanA, "/api/v1/pembelian-bahan/{$dicatatAkuntan->id}")['verifikasi']);
        $this->assertFalse($this->izinDetail($this->akuntanA, "/api/v1/pembelian-bahan/{$final->id}")['verifikasi']);

        // Admin/kepala hanya baca (admin tidak pernah memutuskan).
        $this->assertSame(['ubah' => false, 'hapus' => false, 'lampirkan' => false, 'verifikasi' => false], $this->izinDetail($this->admin, "/api/v1/pembelian-bahan/{$diajukan->id}"));
        $this->assertSame(['ubah' => false, 'hapus' => false, 'lampirkan' => false, 'verifikasi' => false], $this->izinDetail($this->kepalaA, "/api/v1/pembelian-bahan/{$diajukan->id}"));

        // Investor: modul tertutup.
        $this->masuk($this->investorAB)->getJson("/api/v1/pembelian-bahan/{$diajukan->id}")->assertForbidden();
    }

    public function test_izin_pengeluaran_dan_investor_tidak_punya_izin_tulis(): void
    {
        $biasa = Pengeluaran::factory()->status(StatusPersetujuanPengeluaran::Diajukan)->create(['sppg_id' => $this->sppgA, 'dicatat_oleh' => $this->akuntanA->id]);
        $disetujui = Pengeluaran::factory()->create(['sppg_id' => $this->sppgA, 'dicatat_oleh' => $this->akuntanA->id]);
        $pembelian = PembelianBahan::factory()->status(StatusPembelianBahan::Terverifikasi)->create(['sppg_id' => $this->sppgA, 'dicatat_oleh' => $this->stafGudangA->id]);
        $dariPembelian = Pengeluaran::factory()->create(['sppg_id' => $this->sppgA, 'dicatat_oleh' => $this->akuntanA->id, 'pembelian_bahan_id' => $pembelian->id]);

        $url = fn (Pengeluaran $p) => "/api/v1/keuangan/pengeluaran/{$p->id}";

        $this->assertSame(['ubah' => true, 'hapus' => true, 'lampirkan' => true, 'putuskan' => false], $this->izinDetail($this->akuntanA, $url($biasa)));
        $this->assertSame(['ubah' => true, 'hapus' => true, 'lampirkan' => false, 'putuskan' => false], $this->izinDetail($this->akuntanA, $url($disetujui)));
        // Hasil verifikasi pembelian: tidak dapat diubah/dihapus/dilampiri.
        $this->assertSame(['ubah' => false, 'hapus' => false, 'lampirkan' => false, 'putuskan' => false], $this->izinDetail($this->akuntanA, $url($dariPembelian)));

        // Admin dan kepala hanya baca; tidak pernah memutuskan.
        foreach ([$this->admin, $this->kepalaA] as $baca) {
            $this->assertSame(['ubah' => false, 'hapus' => false, 'lampirkan' => false, 'putuskan' => false], $this->izinDetail($baca, $url($biasa)));
        }

        // Investor (baca saja): tanpa ubah/hapus/lampirkan; hak putuskan hanya untuk pengajuan yang menunggu.
        $this->assertSame(['ubah' => false, 'hapus' => false, 'lampirkan' => false, 'putuskan' => true], $this->izinDetail($this->investorAB, $url($biasa)));
        $this->assertSame(['ubah' => false, 'hapus' => false, 'lampirkan' => false, 'putuskan' => false], $this->izinDetail($this->investorAB, $url($disetujui)));

        // Peran tanpa modul keuangan.
        $this->masuk($this->stafGudangA)->getJson($url($biasa))->assertForbidden();
    }

    // ------------------------------------------------------------------ notifikasi perjalanan

    public function test_izin_ubah_status_notifikasi_perjalanan(): void
    {
        $jadwalDist = JadwalDistribusi::factory()->create(['sppg_id' => $this->sppgA]);
        $milik = NotifikasiPerjalanan::factory()->create(['sppg_id' => $this->sppgA, 'jadwal_distribusi_id' => $jadwalDist->id, 'dipicu_oleh' => $this->relawanDistribusiA->id]);
        $tiba = NotifikasiPerjalanan::factory()->tiba()->create(['sppg_id' => $this->sppgA, 'jadwal_distribusi_id' => $jadwalDist->id, 'dipicu_oleh' => $this->relawanDistribusiA->id]);
        $kepala = NotifikasiPerjalanan::factory()->create(['sppg_id' => $this->sppgA, 'jadwal_distribusi_id' => $jadwalDist->id, 'dipicu_oleh' => $this->kepalaA->id]);

        $url = fn (NotifikasiPerjalanan $n) => "/api/v1/notifikasi-perjalanan/{$n->id}";

        $this->assertSame(['ubah_status' => true], $this->izinDetail($this->relawanDistribusiA, $url($milik)));
        $this->assertSame(['ubah_status' => false], $this->izinDetail($this->relawanDistribusiA, $url($tiba)), 'status final tidak bisa diubah');
        $this->assertSame(['ubah_status' => true], $this->izinDetail($this->kepalaA, $url($milik)));
        $this->assertSame(['ubah_status' => true], $this->izinDetail($this->kepalaA, $url($kepala)));
        // Admin hanya baca.
        $this->assertSame(['ubah_status' => false], $this->izinDetail($this->admin, $url($milik)));
        // Relawan hanya perjalanan yang ia picu: milik kepala tidak terlihat sama sekali (403).
        $this->masuk($this->relawanDistribusiA)->getJson($url($kepala))->assertForbidden();
        $this->masuk($this->relawanA)->getJson($url($milik))->assertForbidden();
        $this->masuk($this->akuntanA)->getJson($url($milik))->assertForbidden();
    }

    // ------------------------------------------------------------------ tanpa N+1

    private function jumlahQuery(User $user, string $url, ?int $harapBaris = null): int
    {
        // Pemanasan (cache konfigurasi/skema) agar hanya biaya per permintaan yang dihitung.
        $this->masuk($user)->getJson($url)->assertOk();
        $this->masuk($user);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $respons = $this->getJson($url)->assertOk();
        $jumlah = count(DB::getQueryLog());
        DB::disableQueryLog();

        if ($harapBaris !== null) {
            $this->assertCount($harapBaris, $respons->json('data'));
            $this->assertArrayHasKey('izin', $respons->json('data.0'), 'izin harus ada pada tiap baris');
        }

        return $jumlah;
    }

    /**
     * Daftar dengan 2 baris vs 10 baris harus memakai jumlah query yang sama (izin dihitung tanpa query per baris).
     *
     * @param  callable(int): void  $buat  membuat $n record SPPG A (dipanggil dua kali, kumulatif)
     */
    private function assertTanpaNPlusSatu(User $user, string $url, callable $buat): void
    {
        $this->forgetActor();
        $buat(2);
        $kecil = $this->jumlahQuery($user, $url.'?per_page=100', 2);
        $this->forgetActor();
        $buat(8);
        $besar = $this->jumlahQuery($user, $url.'?per_page=100', 10);

        $this->assertSame($kecil, $besar, "N+1 pada {$url}: {$kecil} query (2 baris) vs {$besar} query (10 baris)");
    }

    public function test_daftar_jadwal_produksi_tanpa_n_plus_satu(): void
    {
        $this->assertTanpaNPlusSatu($this->relawanA, '/api/v1/jadwal', fn (int $n) => JadwalProduksi::factory()->count($n)->create(['sppg_id' => $this->sppgA, 'dibuat_oleh' => $this->relawanA->id]));
    }

    public function test_daftar_jadwal_distribusi_tanpa_n_plus_satu(): void
    {
        $this->assertTanpaNPlusSatu($this->relawanDistribusiA, '/api/v1/jadwal/distribusi', fn (int $n) => JadwalDistribusi::factory()->count($n)->create(['sppg_id' => $this->sppgA, 'dibuat_oleh' => $this->relawanDistribusiA->id]));
    }

    public function test_daftar_log_produksi_tanpa_n_plus_satu(): void
    {
        $this->assertTanpaNPlusSatu($this->relawanA, '/api/v1/log-produksi', fn (int $n) => LogProduksi::factory()->count($n)->create(['sppg_id' => $this->sppgA, 'dicatat_oleh' => $this->relawanA->id]));
    }

    public function test_daftar_bukti_distribusi_tanpa_n_plus_satu(): void
    {
        $this->assertTanpaNPlusSatu($this->relawanDistribusiA, '/api/v1/bukti-distribusi', fn (int $n) => BuktiDistribusi::factory()->count($n)->create(['sppg_id' => $this->sppgA, 'dicatat_oleh' => $this->relawanDistribusiA->id]));
    }

    public function test_daftar_pembelian_bahan_tanpa_n_plus_satu(): void
    {
        $this->assertTanpaNPlusSatu($this->akuntanA, '/api/v1/pembelian-bahan', fn (int $n) => PembelianBahan::factory()->count($n)->create(['sppg_id' => $this->sppgA, 'dicatat_oleh' => $this->stafGudangA->id]));
    }

    public function test_daftar_pengeluaran_tanpa_n_plus_satu(): void
    {
        $this->assertTanpaNPlusSatu($this->akuntanA, '/api/v1/keuangan/pengeluaran', fn (int $n) => Pengeluaran::factory()->count($n)->status(StatusPersetujuanPengeluaran::Diajukan)->create(['sppg_id' => $this->sppgA, 'dicatat_oleh' => $this->akuntanA->id]));
    }

    public function test_daftar_pengeluaran_investor_tanpa_n_plus_satu(): void
    {
        $this->assertTanpaNPlusSatu($this->investorAB, '/api/v1/keuangan/pengeluaran', fn (int $n) => Pengeluaran::factory()->count($n)->status(StatusPersetujuanPengeluaran::Diajukan)->create(['sppg_id' => $this->sppgA, 'dicatat_oleh' => $this->akuntanA->id]));
    }

    public function test_daftar_notifikasi_perjalanan_tanpa_n_plus_satu(): void
    {
        $this->assertTanpaNPlusSatu($this->kepalaA, '/api/v1/notifikasi-perjalanan', fn (int $n) => NotifikasiPerjalanan::factory()->count($n)->create(['sppg_id' => $this->sppgA, 'dipicu_oleh' => $this->kepalaA->id]));
    }
}
