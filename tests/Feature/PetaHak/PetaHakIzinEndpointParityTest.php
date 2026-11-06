<?php

namespace Tests\Feature\PetaHak;

use App\Enums\Role;
use App\Enums\StatusJadwalDistribusi;
use App\Enums\StatusJadwalProduksi;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Testing\TestResponse;

/**
 * Parity `izin` per record vs ENDPOINT NYATA. Untuk record dalam keadaan normal (status yang boleh diubah), kunci
 * `izin` false berarti penolakan karena HAK -> endpoint 403; true -> endpoint TIDAK 403 (200/201/204, atau 422/409 bila
 * badan/keadaan ditolak service). Sembilan profil; relawan juga diuji pada record milik sendiri hari ini, milik sendiri
 * kemarin, dan milik orang lain. Profil yang tidak dapat membaca record (detail 403) tidak punya `izin` -> semua aksi 403.
 */
class PetaHakIzinEndpointParityTest extends PetaHakTestCase
{
    private int $benar = 0;

    private int $salah = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // Ratusan permintaan oleh pengguna yang sama: nonaktifkan pembatas laju (bukan bagian yang diuji).
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function lain(User $user): User
    {
        return $user->is($this->stafGudangA) ? $this->kepalaA : $this->stafGudangA;
    }

    /** @return list<array{0: string, 1: User, 2: bool}> varian [nama, pemilik, kemarin] */
    private function varian(User $user, bool $berdasarPemilik): array
    {
        if ($berdasarPemilik) {
            return $user->role === Role::Relawan
                ? [['milik-hari-ini', $user, false], ['milik-kemarin', $user, true], ['milik-orang-lain', $this->lain($user), false]]
                : [['milik-orang-lain', $this->lain($user), false]];
        }

        return [['dicatat-orang-lain', $this->lain($user), false], ['dicatat-sendiri', $user, false]];
    }

    /**
     * @param  callable(User, bool): Model  $buat  fixture baru di SPPG A (pemilik, kemarin)
     * @param  callable(Model): string  $urlDetail
     * @param  array<string, callable(Model): TestResponse>  $aksi  kunci izin => panggilan endpoint
     */
    private function uji(callable $buat, callable $urlDetail, array $aksi, bool $berdasarPemilik = true): void
    {
        foreach ($this->profil() as $nama => $user) {
            foreach ($this->varian($user, $berdasarPemilik) as [$varian, $pemilik, $kemarin]) {
                $this->forgetActor();
                $contoh = $buat($pemilik, $kemarin);
                $detail = $this->masuk($user)->getJson($urlDetail($contoh));
                $izin = $detail->getStatusCode() === 200 ? (array) $detail->json('data.izin') : [];

                foreach ($aksi as $kunci => $panggil) {
                    $this->forgetActor();
                    $record = $buat($pemilik, $kemarin);
                    $this->masuk($user);
                    $status = $panggil($record)->getStatusCode();
                    $konteks = "{$nama} / {$varian} / izin.{$kunci}";

                    if (($izin[$kunci] ?? false) === true) {
                        $this->assertNotSame(403, $status, "{$konteks}=true tetapi endpoint 403");
                        $this->assertLessThan(500, $status, $konteks);
                        $this->benar++;
                    } else {
                        $this->assertSame(403, $status, "{$konteks}=false/tidak ada tetapi endpoint {$status}");
                        $this->salah++;
                    }
                }
            }
        }

        $this->assertGreaterThan(0, $this->benar, 'harus ada kasus izin=true yang diuji');
        $this->assertGreaterThan(0, $this->salah, 'harus ada kasus izin=false yang diuji');
    }

    private function waktu(bool $kemarin): array
    {
        return $kemarin ? ['created_at' => now()->subDay()] : [];
    }

    public function test_jadwal_produksi(): void
    {
        $this->uji(
            fn (User $p, bool $k) => JadwalProduksi::factory()->create(['sppg_id' => $this->sppgA, 'dibuat_oleh' => $p->id] + $this->waktu($k)),
            fn (Model $r) => "/api/v1/jadwal/{$r->id}",
            [
                'ubah' => fn (Model $r) => $this->patchJson("/api/v1/jadwal/{$r->id}", ['catatan' => 'uji']),
                'hapus' => fn (Model $r) => $this->deleteJson("/api/v1/jadwal/{$r->id}"),
            ],
        );
    }

    public function test_jadwal_distribusi(): void
    {
        $this->uji(
            fn (User $p, bool $k) => JadwalDistribusi::factory()->create(['sppg_id' => $this->sppgA, 'dibuat_oleh' => $p->id] + $this->waktu($k)),
            fn (Model $r) => "/api/v1/jadwal/distribusi/{$r->id}",
            [
                'ubah' => fn (Model $r) => $this->patchJson("/api/v1/jadwal/distribusi/{$r->id}", ['catatan' => 'uji']),
                'hapus' => fn (Model $r) => $this->deleteJson("/api/v1/jadwal/distribusi/{$r->id}"),
            ],
        );
    }

    public function test_log_produksi(): void
    {
        $this->uji(
            fn (User $p, bool $k) => LogProduksi::factory()->create(['sppg_id' => $this->sppgA, 'dicatat_oleh' => $p->id] + $this->waktu($k)),
            fn (Model $r) => "/api/v1/log-produksi/{$r->id}",
            [
                'ubah' => fn (Model $r) => $this->patchJson("/api/v1/log-produksi/{$r->id}", ['kendala' => 'uji']),
                'hapus' => fn (Model $r) => $this->deleteJson("/api/v1/log-produksi/{$r->id}"),
            ],
        );
    }

    public function test_bukti_distribusi(): void
    {
        $this->uji(
            fn (User $p, bool $k) => BuktiDistribusi::factory()->create(['sppg_id' => $this->sppgA, 'dicatat_oleh' => $p->id] + $this->waktu($k)),
            fn (Model $r) => "/api/v1/bukti-distribusi/{$r->id}",
            [
                'ubah' => fn (Model $r) => $this->patchJson("/api/v1/bukti-distribusi/{$r->id}", ['catatan' => 'uji']),
                'hapus' => fn (Model $r) => $this->deleteJson("/api/v1/bukti-distribusi/{$r->id}"),
            ],
        );
    }

    public function test_pembelian_bahan(): void
    {
        $this->uji(
            fn (User $p) => PembelianBahan::factory()->create(['sppg_id' => $this->sppgA, 'dicatat_oleh' => $p->id]),
            fn (Model $r) => "/api/v1/pembelian-bahan/{$r->id}",
            [
                'ubah' => fn (Model $r) => $this->patchJson("/api/v1/pembelian-bahan/{$r->id}", ['catatan' => 'uji']),
                'lampirkan' => fn (Model $r) => $this->post('/api/v1/lampiran', ['modul' => 'pembelian-bahan', 'lampiranable_id' => $r->id, 'berkas' => $this->fotoJpeg('nota.jpg')], ['Accept' => 'application/json']),
                'verifikasi' => fn (Model $r) => $this->postJson("/api/v1/pembelian-bahan/{$r->id}/verifikasi", ['keputusan' => 'disetujui', 'versi' => 1]),
            ],
            berdasarPemilik: false,
        );
    }

    public function test_pembelian_bahan_tidak_punya_endpoint_hapus_dan_izin_hapus_selalu_false(): void
    {
        $pembelian = PembelianBahan::factory()->create(['sppg_id' => $this->sppgA, 'dicatat_oleh' => $this->stafGudangA->id]);

        foreach ($this->profil() as $nama => $user) {
            $this->masuk($user);
            $this->assertContains($this->deleteJson("/api/v1/pembelian-bahan/{$pembelian->id}")->getStatusCode(), [404, 405], $nama);
            $detail = $this->masuk($user)->getJson("/api/v1/pembelian-bahan/{$pembelian->id}");

            if ($detail->getStatusCode() === 200) {
                $this->assertFalse($detail->json('data.izin.hapus'), $nama);
            }
        }
    }

    public function test_pengeluaran(): void
    {
        $this->uji(
            fn (User $p) => Pengeluaran::factory()->status(StatusPersetujuanPengeluaran::Diajukan)->create(['sppg_id' => $this->sppgA, 'dicatat_oleh' => $p->id]),
            fn (Model $r) => "/api/v1/keuangan/pengeluaran/{$r->id}",
            [
                'ubah' => fn (Model $r) => $this->patchJson("/api/v1/keuangan/pengeluaran/{$r->id}", ['uraian' => 'uji', 'versi' => 1]),
                'hapus' => fn (Model $r) => $this->deleteJson("/api/v1/keuangan/pengeluaran/{$r->id}"),
                'lampirkan' => fn (Model $r) => $this->post('/api/v1/lampiran', ['modul' => 'keuangan-pengeluaran', 'lampiranable_id' => $r->id, 'berkas' => $this->fotoJpeg('nota.jpg')], ['Accept' => 'application/json']),
                'putuskan' => fn (Model $r) => $this->postJson("/api/v1/keuangan/pengeluaran/{$r->id}/keputusan", ['keputusan' => 'disetujui', 'versi' => 1]),
            ],
            berdasarPemilik: false,
        );
    }

    public function test_notifikasi_perjalanan(): void
    {
        $this->uji(
            fn (User $p, bool $k) => NotifikasiPerjalanan::factory()->create(['sppg_id' => $this->sppgA, 'dipicu_oleh' => $p->id] + $this->waktu($k)),
            fn (Model $r) => "/api/v1/notifikasi-perjalanan/{$r->id}",
            [
                'ubah_status' => fn (Model $r) => $this->postJson("/api/v1/notifikasi-perjalanan/{$r->id}/status", ['status' => 'dalam_perjalanan']),
            ],
        );
    }

    // ------------------------------------------------------------------ keadaan record: izin false -> endpoint tidak pernah sukses

    private function bukanSukses(TestResponse $respons, string $konteks): void
    {
        $this->assertContains($respons->getStatusCode(), [403, 409, 422], "{$konteks}: izin=false tetapi endpoint {$respons->getStatusCode()}");
    }

    public function test_izin_false_karena_keadaan_record_endpoint_menolak(): void
    {
        $dimasak = JadwalProduksi::factory()->create(['sppg_id' => $this->sppgA, 'dibuat_oleh' => $this->kepalaA->id, 'status' => StatusJadwalProduksi::Dimasak]);
        $berangkat = JadwalDistribusi::factory()->create(['sppg_id' => $this->sppgA, 'dibuat_oleh' => $this->kepalaA->id, 'status' => StatusJadwalDistribusi::Berangkat]);
        $final = PembelianBahan::factory()->status(StatusPembelianBahan::Terverifikasi)->create(['sppg_id' => $this->sppgA, 'dicatat_oleh' => $this->stafGudangA->id]);
        $disetujui = Pengeluaran::factory()->create(['sppg_id' => $this->sppgA, 'dicatat_oleh' => $this->akuntanA->id]);
        $dariPembelian = Pengeluaran::factory()->create(['sppg_id' => $this->sppgA, 'dicatat_oleh' => $this->akuntanA->id, 'pembelian_bahan_id' => $final->id]);
        $tiba = NotifikasiPerjalanan::factory()->tiba()->create(['sppg_id' => $this->sppgA, 'dipicu_oleh' => $this->kepalaA->id]);
        $this->forgetActor();

        $izin = fn (User $u, string $url): array => (array) $this->masuk($u)->getJson($url)->assertOk()->json('data.izin');

        // Jadwal yang sudah berjalan tidak dapat dihapus (hak ada, keadaan tidak).
        $this->assertFalse($izin($this->kepalaA, "/api/v1/jadwal/{$dimasak->id}")['hapus']);
        $this->bukanSukses($this->masuk($this->kepalaA)->deleteJson("/api/v1/jadwal/{$dimasak->id}"), 'jadwal dimasak');
        $this->assertFalse($izin($this->kepalaA, "/api/v1/jadwal/distribusi/{$berangkat->id}")['hapus']);
        $this->bukanSukses($this->masuk($this->kepalaA)->deleteJson("/api/v1/jadwal/distribusi/{$berangkat->id}"), 'distribusi berangkat');

        // Pembelian terverifikasi final: tidak dapat diubah, dilampiri, atau diverifikasi ulang.
        $i = $izin($this->stafGudangA, "/api/v1/pembelian-bahan/{$final->id}");
        $this->assertFalse($i['ubah']);
        $this->assertFalse($i['lampirkan']);
        $this->bukanSukses($this->masuk($this->stafGudangA)->patchJson("/api/v1/pembelian-bahan/{$final->id}", ['catatan' => 'uji']), 'pembelian final ubah');
        $this->bukanSukses($this->masuk($this->stafGudangA)->post('/api/v1/lampiran', ['modul' => 'pembelian-bahan', 'lampiranable_id' => $final->id, 'berkas' => $this->fotoJpeg('n.jpg')], ['Accept' => 'application/json']), 'pembelian final lampirkan');
        $this->assertFalse($izin($this->akuntanA, "/api/v1/pembelian-bahan/{$final->id}")['verifikasi']);
        $this->bukanSukses($this->masuk($this->akuntanA)->postJson("/api/v1/pembelian-bahan/{$final->id}/verifikasi", ['keputusan' => 'disetujui', 'versi' => 1]), 'pembelian final verifikasi');

        // Pengeluaran disetujui: tidak dapat dilampiri; hasil pembelian: tidak dapat diubah/dihapus/dilampiri.
        $this->assertFalse($izin($this->akuntanA, "/api/v1/keuangan/pengeluaran/{$disetujui->id}")['lampirkan']);
        $this->bukanSukses($this->masuk($this->akuntanA)->post('/api/v1/lampiran', ['modul' => 'keuangan-pengeluaran', 'lampiranable_id' => $disetujui->id, 'berkas' => $this->fotoJpeg('n.jpg')], ['Accept' => 'application/json']), 'pengeluaran disetujui lampirkan');
        $i = $izin($this->akuntanA, "/api/v1/keuangan/pengeluaran/{$dariPembelian->id}");
        $this->assertSame([false, false, false], [$i['ubah'], $i['hapus'], $i['lampirkan']]);
        $this->bukanSukses($this->masuk($this->akuntanA)->patchJson("/api/v1/keuangan/pengeluaran/{$dariPembelian->id}", ['uraian' => 'uji', 'versi' => 1]), 'pengeluaran dari pembelian ubah');
        $this->bukanSukses($this->masuk($this->akuntanA)->deleteJson("/api/v1/keuangan/pengeluaran/{$dariPembelian->id}"), 'pengeluaran dari pembelian hapus');

        // Perjalanan yang sudah tiba tidak dapat diubah statusnya.
        $this->assertFalse($izin($this->kepalaA, "/api/v1/notifikasi-perjalanan/{$tiba->id}")['ubah_status']);
        $this->bukanSukses($this->masuk($this->kepalaA)->postJson("/api/v1/notifikasi-perjalanan/{$tiba->id}/status", ['status' => 'dibatalkan', 'alasan' => 'uji']), 'perjalanan tiba');
    }
}
