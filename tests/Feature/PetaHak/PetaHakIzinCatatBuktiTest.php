<?php

namespace Tests\Feature\PetaHak;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Enums\StatusJadwalDistribusi;
use App\Http\Resources\Jadwal\JadwalDistribusiResource;
use App\Models\JadwalDistribusi;
use App\Models\JadwalProduksi;
use App\Models\PemeriksaanMutu;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * `izin.catat_bukti` pada baris jadwal distribusi (GET /jadwal/distribusi dan detail) memakai AturanCatatBukti yang
 * SAMA dengan POST /bukti-distribusi. Parity terhadap endpoint nyata untuk sembilan profil x tiap keadaan jadwal:
 * izin true => endpoint tidak 403 (bisa 201, atau 422 untuk syarat relasional); izin false => endpoint menolak (403/422),
 * tidak pernah 201. Plus: tanpa N+1 (2 vs 10 baris) dan tidak ada query tambahan untuk izin.
 */
class PetaHakIzinCatatBuktiTest extends PetaHakTestCase
{
    private User $relawanDistribusiLain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->relawanDistribusiLain = $this->userOf(Role::Relawan, $this->sppgA, RelawanUnit::Distribusi);
        Carbon::setTestNow(Carbon::parse('2026-11-02 10:00:00', 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Jadwal baru di SPPG A (dibuat tanpa pengguna login) dengan batch produksi ber-pemeriksaan mutu. */
    private function jadwal(array $atribut = [], bool $layak = true, ?int $sppg = null): JadwalDistribusi
    {
        $this->forgetActor();
        $sppg ??= $this->sppgA;
        $jadwal = JadwalDistribusi::factory()->siap()->create($atribut + [
            'sppg_id' => $sppg,
            'tanggal_distribusi' => '2026-11-02',
            'jumlah_porsi' => 100,
        ]);
        PemeriksaanMutu::factory()->{$layak ? 'layak' : 'tidakLayak'}()->create(['sppg_id' => $sppg, 'jadwal_produksi_id' => $jadwal->jadwal_produksi_id]);

        return $jadwal;
    }

    private function kirim(JadwalDistribusi $jadwal): int
    {
        return $this->post('/api/v1/bukti-distribusi', [
            'jadwal_distribusi_id' => $jadwal->id,
            'porsi_diterima' => 100,
            'nama_pic' => 'Ibu Contoh',
            'foto' => $this->fotoJpeg(),
        ], ['Accept' => 'application/json'])->getStatusCode();
    }

    /** @return array<string, array{0: array<string, mixed>, 1: bool}> nama => [atribut jadwal, layak] */
    private function keadaan(User $user): array
    {
        return [
            'siap-hari-ini-tanpa-petugas' => [[], true],
            'ditugaskan-ke-pengguna-ini' => [['petugas_id' => $user->id], true],
            'ditugaskan-ke-petugas-lain' => [['petugas_id' => $this->relawanDistribusiLain->id], true],
            'kemarin' => [['tanggal_distribusi' => '2026-11-01'], true],
            'berangkat' => [['status' => StatusJadwalDistribusi::Berangkat], true],
            'diterima-tanpa-bukti' => [['status' => StatusJadwalDistribusi::Diterima], true],
            'menunggu' => [['status' => StatusJadwalDistribusi::Menunggu], true],
            'dibatalkan' => [['status' => StatusJadwalDistribusi::Dibatalkan], true],
            'tidak-layak-mutu' => [[], false],
        ];
    }

    public function test_izin_catat_bukti_sama_dengan_endpoint_nyata_untuk_semua_peran_dan_keadaan(): void
    {
        $benar = $salah = 0;

        foreach ($this->profil() as $nama => $user) {
            foreach ($this->keadaan($user) as $keadaan => [$atribut, $layak]) {
                $contoh = $this->jadwal($atribut, $layak);
                $detail = $this->masuk($user)->getJson("/api/v1/jadwal/distribusi/{$contoh->id}");
                $izin = $detail->getStatusCode() === 200 ? $detail->json('data.izin.catat_bukti') : null;

                // Detail dan daftar harus sependapat.
                if ($izin !== null) {
                    $this->assertIsBool($izin, "{$nama} / {$keadaan}");
                    $daftar = collect($this->getJson("/api/v1/jadwal/distribusi?per_page=100&jadwal_produksi_id={$contoh->jadwal_produksi_id}")->json('data'))->firstWhere('id', $contoh->id);
                    $this->assertSame($izin, $daftar['izin']['catat_bukti'] ?? null, "{$nama} / {$keadaan}: daftar vs detail");
                }

                $this->forgetActor();
                $record = $this->jadwal($atribut, $layak);
                $status = $this->masuk($user)->kirim($record);
                $konteks = "{$nama} / {$keadaan}";

                if ($izin === true) {
                    $this->assertNotSame(403, $status, "{$konteks}: izin.catat_bukti=true tetapi POST 403");
                    $this->assertLessThan(500, $status, $konteks);
                    $benar++;
                } else {
                    $this->assertContains($status, [403, 404, 422], "{$konteks}: izin.catat_bukti=false/tidak ada tetapi POST {$status}");
                    $this->assertNotSame(201, $status, $konteks);
                    $salah++;
                }
            }
        }

        $this->assertGreaterThan(0, $benar);
        $this->assertGreaterThan(0, $salah);
    }

    public function test_izin_catat_bukti_true_berarti_bukti_berhasil_dicatat_pada_keadaan_normal(): void
    {
        foreach (['kepala_sppg' => $this->kepalaA, 'relawan.distribusi' => $this->relawanDistribusiA] as $nama => $user) {
            $jadwal = $this->jadwal();
            $this->assertTrue($this->masuk($user)->getJson("/api/v1/jadwal/distribusi/{$jadwal->id}")->json('data.izin.catat_bukti'), $nama);
            $this->assertSame(201, $this->kirim($jadwal), $nama);
        }
    }

    public function test_tabel_kebenaran_izin_catat_bukti_per_peran(): void
    {
        $this->forgetActor();
        $tanpaPetugas = $this->jadwal();
        $milikRelawan = $this->jadwal(['petugas_id' => $this->relawanDistribusiA->id]);
        $milikLain = $this->jadwal(['petugas_id' => $this->relawanDistribusiLain->id]);
        $kemarin = $this->jadwal(['tanggal_distribusi' => '2026-11-01']);
        $menunggu = $this->jadwal(['status' => StatusJadwalDistribusi::Menunggu]);
        $dibatalkan = $this->jadwal(['status' => StatusJadwalDistribusi::Dibatalkan]);
        $ditahan = $this->jadwal(layak: false);

        $izin = fn (User $u, JadwalDistribusi $j): bool => $this->masuk($u)->getJson("/api/v1/jadwal/distribusi/{$j->id}")->assertOk()->json('data.izin.catat_bukti');

        // Kepala SPPG: tidak terikat petugas/hari; tetap terikat status dan gerbang mutu.
        $this->assertTrue($izin($this->kepalaA, $tanpaPetugas));
        $this->assertTrue($izin($this->kepalaA, $milikLain));
        $this->assertTrue($izin($this->kepalaA, $kemarin));
        $this->assertFalse($izin($this->kepalaA, $menunggu));
        $this->assertFalse($izin($this->kepalaA, $dibatalkan));
        $this->assertFalse($izin($this->kepalaA, $ditahan));

        // Relawan distribusi: hanya jadwal hari ini yang tidak ditugaskan ke orang lain.
        $this->assertTrue($izin($this->relawanDistribusiA, $tanpaPetugas));
        $this->assertTrue($izin($this->relawanDistribusiA, $milikRelawan));
        $this->assertFalse($izin($this->relawanDistribusiA, $milikLain), 'petugas lain');
        $this->assertFalse($izin($this->relawanDistribusiA, $kemarin), 'bukan hari ini');
        $this->assertFalse($izin($this->relawanDistribusiA, $menunggu));
        $this->assertFalse($izin($this->relawanDistribusiA, $ditahan));

        // Peran lain: Policy create bukti menolak (admin/ahli_gizi/akuntan hanya baca; staf_gudang tanpa hak).
        foreach (['admin' => $this->admin, 'ahli_gizi' => $this->ahliGiziA, 'akuntan' => $this->akuntanA] as $nama => $user) {
            $respons = $this->masuk($user)->getJson("/api/v1/jadwal/distribusi/{$tanpaPetugas->id}");

            if ($respons->getStatusCode() === 200) {
                $this->assertFalse($respons->json('data.izin.catat_bukti'), $nama);
            }
        }
    }

    public function test_izin_catat_bukti_ada_di_detail_jadwal(): void
    {
        $jadwal = $this->jadwal();
        $this->masuk($this->kepalaA)->getJson("/api/v1/jadwal/distribusi/{$jadwal->id}")
            ->assertOk()->assertJsonStructure(['data' => ['izin' => ['ubah', 'hapus', 'catat_bukti']]]);
    }

    public function test_relasi_produksi_tidak_dimuat_gagal_tertutup(): void
    {
        $jadwal = $this->jadwal();
        $segar = JadwalDistribusi::query()->withoutGlobalScopes()->findOrFail($jadwal->id); // tanpa relasi
        $request = request();
        $request->setUserResolver(fn () => $this->kepalaA);

        $this->assertFalse($segar->relationLoaded('jadwalProduksi'));
        $this->assertFalse((new JadwalDistribusiResource($segar))->toArray($request)['izin']['catat_bukti']);

        // Produksi dimuat tetapi pemeriksaan terbaru tidak: tetap false.
        $setengah = JadwalDistribusi::query()->withoutGlobalScopes()->with('jadwalProduksi')->findOrFail($jadwal->id);
        $this->assertFalse((new JadwalDistribusiResource($setengah))->toArray($request)['izin']['catat_bukti']);

        // Dimuat lengkap: true.
        $penuh = JadwalDistribusi::query()->withoutGlobalScopes()->with('jadwalProduksi.pemeriksaanTerbaru')->findOrFail($jadwal->id);
        $this->assertTrue((new JadwalDistribusiResource($penuh))->toArray($request)['izin']['catat_bukti']);
    }

    public function test_batch_produksi_terhapus_lunak_konsisten_dengan_service(): void
    {
        $jadwal = $this->jadwal();
        JadwalProduksi::query()->withoutGlobalScopes()->findOrFail($jadwal->jadwal_produksi_id)->delete();

        $this->assertTrue($this->masuk($this->kepalaA)->getJson("/api/v1/jadwal/distribusi/{$jadwal->id}")->assertOk()->json('data.izin.catat_bukti'));
        $this->assertSame(201, $this->kirim($jadwal), 'service menerima batch terhapus lunak yang layak');
    }

    // ------------------------------------------------------------------ tanpa N+1

    private function jumlahQuery(User $user, int $harapBaris): int
    {
        $url = '/api/v1/jadwal/distribusi?per_page=100';
        $this->masuk($user)->getJson($url)->assertOk(); // pemanasan
        $this->masuk($user);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $respons = $this->getJson($url)->assertOk();
        $jumlah = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount($harapBaris, $respons->json('data'));

        foreach ($respons->json('data') as $baris) {
            $this->assertIsBool($baris['izin']['catat_bukti']);
        }

        return $jumlah;
    }

    public function test_daftar_jadwal_distribusi_dengan_izin_catat_bukti_tanpa_n_plus_satu(): void
    {
        foreach ([$this->kepalaA, $this->relawanDistribusiA] as $user) {
            JadwalDistribusi::query()->withoutGlobalScopes()->delete();

            foreach (range(1, 2) as $_) {
                $this->jadwal(['petugas_id' => $user->id]);
            }

            $kecil = $this->jumlahQuery($user, 2);

            foreach (range(1, 8) as $_) {
                $this->jadwal(['petugas_id' => $user->id]);
            }

            $besar = $this->jumlahQuery($user, 10);

            $this->assertSame($kecil, $besar, "N+1 izin.catat_bukti ({$user->role->value}): {$kecil} query (2 baris) vs {$besar} (10 baris)");
        }
    }

    public function test_menghitung_izin_catat_bukti_tidak_menambah_query(): void
    {
        $jadwal = $this->jadwal();
        $jadwal = JadwalDistribusi::query()->withoutGlobalScopes()->with('jadwalProduksi.pemeriksaanTerbaru')->findOrFail($jadwal->id);
        $this->masuk($this->relawanDistribusiA);
        $resource = new JadwalDistribusiResource($jadwal);
        $request = request();
        $request->setUserResolver(fn () => $this->relawanDistribusiA);
        $resource->toArray($request); // pemanasan cache Rbac

        DB::flushQueryLog();
        DB::enableQueryLog();
        $izin = $resource->toArray($request)['izin'];
        $jumlah = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(0, $jumlah);
        $this->assertTrue($izin['catat_bukti']);
    }
}
