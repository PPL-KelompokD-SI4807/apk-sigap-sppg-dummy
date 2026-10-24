<?php

namespace Tests\Feature\BuktiDistribusi;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Enums\StatusAiDraft;
use App\Enums\StatusJadwalDistribusi;
use App\Enums\StatusPerjalanan;
use App\Events\BuktiDistribusiDicatat;
use App\Events\DistribusiTerlambat;
use App\Models\AiDraft;
use App\Models\AuditLog;
use App\Models\BuktiDistribusi;
use App\Models\JadwalDistribusi;
use App\Models\Lampiran;
use App\Models\NotifikasiPerjalanan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\GambarUji;
use Tests\Support\SkenarioOperasional;
use Tests\TestCase;

/** PBI 13 Data Bukti Distribusi. */
class BuktiDistribusiTest extends TestCase
{
    use GambarUji, RefreshDatabase, SkenarioOperasional;

    private const HARI = '2026-11-02';

    private int $sppgA;

    private int $sppgB;

    private User $kurir;

    private User $kepala;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('uploads');
        Carbon::setTestNow(Carbon::parse(self::HARI.' 10:00:00', 'Asia/Jakarta'));
        $this->sppgA = $this->createSppg('SPPG A');
        $this->sppgB = $this->createSppg('SPPG B');
        $this->kurir = $this->pengguna(Role::Relawan, $this->sppgA, RelawanUnit::Distribusi);
        $this->kepala = $this->pengguna(Role::KepalaSppg, $this->sppgA);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<string, mixed> $jadwal */
    private function jadwal(array $jadwal = [], bool $layak = true, ?int $sppg = null): JadwalDistribusi
    {
        return $this->jadwalSiap($sppg ?? $this->sppgA, ['nama' => 'SDN Fiktif 1'], array_merge([
            'tanggal_distribusi' => self::HARI,
            'jam_berangkat' => '09:15:00',
            'jam_rencana_tiba' => '09:45:00',
            'jumlah_porsi' => 200,
        ], $jadwal), $layak);
    }

    /** @param array<string, mixed> $override */
    private function payload(JadwalDistribusi $jadwal, array $override = []): array
    {
        return array_merge([
            'jadwal_distribusi_id' => $jadwal->id,
            'porsi_diterima' => 200,
            'nama_pic' => 'Ibu Contoh',
            'no_berita_acara' => 'BA-0001',
            'catatan' => 'Diterima di ruang guru',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'foto' => $this->fotoJpeg(),
        ], $override);
    }

    /** @param array<string, mixed> $data */
    private function kirim(array $data, array $headers = []): TestResponse
    {
        return $this->post('/api/v1/bukti-distribusi', $data, $headers + ['Accept' => 'application/json']);
    }

    public function test_relawan_distribusi_mencatat_bukti_dengan_foto_dan_waktu_server(): void
    {
        Event::fake([BuktiDistribusiDicatat::class, DistribusiTerlambat::class]);
        $jadwal = $this->jadwal();
        Sanctum::actingAs($this->kurir);

        $res = $this->kirim($this->payload($jadwal, ['waktu_tiba' => '2026-11-02 08:00:00', 'berita_acara' => $this->berkas($this->bytesPng(), 'ba.png')]))
            ->assertCreated()
            ->assertJsonPath('data.sppg_id', $this->sppgA)
            ->assertJsonPath('data.porsi_dikirim', 200)
            ->assertJsonPath('data.porsi_diterima', 200)
            ->assertJsonPath('data.selisih_porsi', 0)
            ->assertJsonPath('data.waktu_tiba', '2026-11-02T10:00:00+07:00')
            ->assertJsonPath('data.acuan_tiba', '2026-11-02T09:45:00+07:00')
            ->assertJsonPath('data.menit_keterlambatan', 15)
            ->assertJsonPath('data.terlambat', false)
            ->assertJsonPath('data.jadwal_distribusi.status', 'diterima')
            ->assertJsonPath('data.dicatat_oleh.id', $this->kurir->id)
            ->assertJsonMissingPath('data.foto_path');

        $this->assertStringStartsWith('/api/v1/lampiran/', $res->json('data.foto.url'));
        $this->assertNotNull($res->json('data.berita_acara.lampiran_id'));
        $this->assertCount(2, $res->json('data.lampiran'));
        $this->assertSame(StatusJadwalDistribusi::Diterima, $jadwal->fresh()->status);

        $bukti = BuktiDistribusi::query()->findOrFail($res->json('data.id'));
        $this->assertMatchesRegularExpression("#^uploads/{$this->sppgA}/bukti-distribusi/[0-9a-f-]{36}\\.jpg$#", $bukti->foto_path);
        Storage::disk('uploads')->assertExists($bukti->foto_path);

        Event::assertDispatched(BuktiDistribusiDicatat::class, fn ($e) => $e->id === $bukti->id && $e->toArray()['titik_layanan']['nama'] === 'SDN Fiktif 1');
        Event::assertNotDispatched(DistribusiTerlambat::class);

        $this->get($res->json('data.foto.url'))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_keterlambatan_dari_estimasi_perjalanan_dan_perjalanan_ditutup(): void
    {
        Event::fake([BuktiDistribusiDicatat::class, DistribusiTerlambat::class]);
        $jadwal = $this->jadwal();
        $perjalanan = NotifikasiPerjalanan::factory()->create([
            'sppg_id' => $this->sppgA,
            'jadwal_distribusi_id' => $jadwal->id,
            'waktu_berangkat' => Carbon::parse(self::HARI.' 09:20:00'),
            'estimasi_tiba' => Carbon::parse(self::HARI.' 09:50:00'),
        ]);
        Carbon::setTestNow(Carbon::parse(self::HARI.' 10:31:00', 'Asia/Jakarta'));
        Sanctum::actingAs($this->kurir);

        $this->kirim($this->payload($jadwal, ['porsi_diterima' => 190, 'catatan_selisih' => '10 porsi tumpah']))
            ->assertCreated()
            ->assertJsonPath('data.terlambat', true)
            ->assertJsonPath('data.menit_keterlambatan', 41)
            ->assertJsonPath('data.acuan_tiba', '2026-11-02T09:50:00+07:00')
            ->assertJsonPath('data.waktu_berangkat', '2026-11-02T09:20:00+07:00')
            ->assertJsonPath('data.selisih_porsi', 10);

        $this->assertSame(StatusPerjalanan::Tiba, $perjalanan->fresh()->status_perjalanan);
        Event::assertDispatched(DistribusiTerlambat::class, function (DistribusiTerlambat $e) use ($jadwal) {
            $p = $e->toArray();

            return $p['jadwal_distribusi_id'] === $jadwal->id && $p['menit_keterlambatan'] === 41 && $p['terlambat'] === true
                && $p['porsi_dikirim'] === 200 && $p['selisih_porsi'] === 10;
        });
        Event::assertDispatchedTimes(DistribusiTerlambat::class, 1);
    }

    public function test_validasi_gagal(): void
    {
        $jadwal = $this->jadwal();
        $lain = $this->jadwal([], true, $this->sppgB);
        Sanctum::actingAs($this->kurir);

        $this->kirim($this->payload($jadwal, ['foto' => null]))->assertUnprocessable()->assertJsonValidationErrors('foto');
        $this->kirim($this->payload($jadwal, ['porsi_diterima' => 201]))->assertUnprocessable()->assertJsonValidationErrors('porsi_diterima');
        $this->kirim($this->payload($jadwal, ['porsi_diterima' => 150]))->assertUnprocessable()->assertJsonValidationErrors('catatan_selisih');
        $this->kirim($this->payload($jadwal, ['nama_pic' => '', 'porsi_diterima' => -1]))->assertUnprocessable()->assertJsonValidationErrors(['nama_pic', 'porsi_diterima']);
        $this->kirim($this->payload($jadwal, ['latitude' => 120]))->assertUnprocessable()->assertJsonValidationErrors('latitude');
        $this->kirim($this->payload($lain))->assertUnprocessable()->assertJsonValidationErrors('jadwal_distribusi_id');
        $this->kirim($this->payload($jadwal, ['foto' => $this->berkas('<?php echo 1; ?>', 'foto.jpg')]))->assertUnprocessable()->assertJsonValidationErrors('foto');

        $this->assertSame(0, BuktiDistribusi::query()->withoutGlobalScopes()->count());
        $this->assertSame([], Storage::disk('uploads')->allFiles());
    }

    public function test_jadwal_dibatalkan_belum_siap_atau_ditahan_mutu_ditolak(): void
    {
        $batal = $this->jadwal(['status' => StatusJadwalDistribusi::Dibatalkan]);
        $menunggu = $this->jadwal(['status' => StatusJadwalDistribusi::Menunggu]);
        $ditahan = $this->jadwal([], false);
        $kemarin = $this->jadwal(['tanggal_distribusi' => '2026-11-01']);
        Sanctum::actingAs($this->kurir);

        foreach ([$batal, $menunggu, $ditahan, $kemarin] as $j) {
            $this->kirim($this->payload($j))->assertUnprocessable()->assertJsonValidationErrors('jadwal_distribusi_id');
        }

        $this->assertSame(StatusJadwalDistribusi::Dibatalkan, $batal->fresh()->status);
        $this->assertSame(0, BuktiDistribusi::query()->count());
        $this->assertSame([], Storage::disk('uploads')->allFiles());
    }

    public function test_satu_bukti_per_jadwal_dan_restore_setelah_hapus(): void
    {
        $jadwal = $this->jadwal();
        Sanctum::actingAs($this->kurir);
        $id = $this->kirim($this->payload($jadwal))->assertCreated()->json('data.id');
        $this->kirim($this->payload($jadwal))->assertUnprocessable()->assertJsonValidationErrors('jadwal_distribusi_id');

        Sanctum::actingAs($this->kepala);
        $this->deleteJson("/api/v1/bukti-distribusi/{$id}")->assertNoContent();
        $this->assertSoftDeleted('bukti_distribusis', ['id' => $id]);
        $this->assertSame(StatusJadwalDistribusi::Berangkat, $jadwal->fresh()->status);
        $this->getJson("/api/v1/bukti-distribusi/{$id}")->assertNotFound();

        $this->kirim($this->payload($jadwal, ['porsi_diterima' => 180, 'catatan_selisih' => 'Koreksi', 'waktu_tiba' => '2026-11-02 09:40:00']))
            ->assertCreated()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.porsi_diterima', 180)
            ->assertJsonPath('data.waktu_tiba', '2026-11-02T09:40:00+07:00')
            ->assertJsonPath('data.menit_keterlambatan', null)
            ->assertJsonPath('data.dicatat_oleh.id', $this->kepala->id);

        $this->assertSame(1, BuktiDistribusi::withTrashed()->count());
        $this->assertTrue(AuditLog::query()->where('auditable_type', BuktiDistribusi::class)->where('auditable_id', $id)->where('event', 'restored')->exists());
    }

    public function test_restore_tidak_membawa_lampiran_bukti_lama(): void
    {
        $jadwal = $this->jadwal();
        Sanctum::actingAs($this->kurir);
        $id = $this->kirim($this->payload($jadwal, ['berita_acara' => $this->fotoJpeg('ba.jpg')]))->assertCreated()->json('data.id');
        $lama = Lampiran::query()->where('lampiranable_id', $id)->get();
        $this->assertCount(2, $lama);

        Sanctum::actingAs($this->kepala);
        $this->deleteJson("/api/v1/bukti-distribusi/{$id}")->assertNoContent();
        $baru = $this->kirim($this->payload($jadwal))->assertCreated()
            ->assertJsonPath('data.id', $id)
            ->assertJsonCount(1, 'data.lampiran')
            ->assertJsonPath('data.berita_acara', null)
            ->json('data');

        // Baris dan berkas lama hilang; hanya foto baru yang terikat dan dapat dibuka.
        foreach ($lama as $l) {
            $this->assertDatabaseMissing('lampirans', ['id' => $l->id]);
            Storage::disk('uploads')->assertMissing($l->path);
            $this->get("/api/v1/lampiran/{$l->id}")->assertNotFound();
        }

        $this->assertNotContains($baru['foto']['lampiran_id'], $lama->pluck('id')->all());
        $this->get($baru['foto']['url'])->assertOk();
    }

    public function test_hak_akses_create_tabel5(): void
    {
        $jadwal = $this->jadwal();
        $lainPetugas = $this->pengguna(Role::Relawan, $this->sppgA, RelawanUnit::Distribusi);
        $milikLain = $this->jadwal(['petugas_id' => $lainPetugas->id]);
        $tanpaHak = [
            $this->pengguna(Role::Relawan, $this->sppgA, RelawanUnit::Dapur),
            $this->pengguna(Role::StafGudang, $this->sppgA),
            $this->pengguna(Role::Akuntan, $this->sppgA),
            $this->pengguna(Role::AhliGizi, $this->sppgA),
            $this->pengguna(Role::Admin, $this->sppgA),
        ];

        $this->kirim($this->payload($jadwal))->assertUnauthorized();

        foreach ($tanpaHak as $u) {
            Sanctum::actingAs($u);
            $this->kirim($this->payload($jadwal))->assertForbidden();
        }

        Sanctum::actingAs($this->kurir);
        $this->kirim($this->payload($milikLain))->assertForbidden();
        $this->assertSame(0, BuktiDistribusi::query()->withoutGlobalScopes()->count());

        Sanctum::actingAs($lainPetugas);
        $this->kirim($this->payload($milikLain))->assertCreated();
    }

    public function test_relawan_hanya_mengubah_miliknya_di_hari_yang_sama_dan_tidak_menghapus(): void
    {
        $kurir2 = $this->pengguna(Role::Relawan, $this->sppgA, RelawanUnit::Distribusi);
        $jadwal = $this->jadwal();
        Sanctum::actingAs($this->kurir);
        $id = $this->kirim($this->payload($jadwal))->json('data.id');

        $this->patchJson("/api/v1/bukti-distribusi/{$id}", ['nama_pic' => 'Bapak Contoh', 'waktu_tiba' => '2026-11-02 07:00:00'])
            ->assertOk()->assertJsonPath('data.nama_pic', 'Bapak Contoh')->assertJsonPath('data.waktu_tiba', '2026-11-02T10:00:00+07:00');
        $this->patchJson("/api/v1/bukti-distribusi/{$id}", ['jadwal_distribusi_id' => $jadwal->id])->assertUnprocessable();
        $this->deleteJson("/api/v1/bukti-distribusi/{$id}")->assertForbidden();

        Sanctum::actingAs($kurir2);
        $this->patchJson("/api/v1/bukti-distribusi/{$id}", ['nama_pic' => 'X'])->assertForbidden();
        $this->getJson("/api/v1/bukti-distribusi/{$id}")->assertForbidden();
        $this->getJson('/api/v1/bukti-distribusi')->assertOk()->assertJsonCount(0, 'data');

        Carbon::setTestNow(Carbon::parse('2026-11-03 08:00:00', 'Asia/Jakarta'));
        Sanctum::actingAs($this->kurir);
        $this->patchJson("/api/v1/bukti-distribusi/{$id}", ['nama_pic' => 'Besok'])->assertForbidden();
        $this->getJson("/api/v1/bukti-distribusi/{$id}")->assertOk();
    }

    public function test_kepala_mengubah_foto_dan_waktu_tiba_dengan_multipart_method_spoofing(): void
    {
        Event::fake([DistribusiTerlambat::class]);
        $jadwal = $this->jadwal();
        Sanctum::actingAs($this->kurir);
        $res = $this->kirim($this->payload($jadwal));
        $id = $res->json('data.id');
        $fotoLama = $res->json('data.foto.lampiran_id');

        Sanctum::actingAs($this->kepala);
        $baru = $this->post("/api/v1/bukti-distribusi/{$id}", [
            '_method' => 'PUT',
            'foto' => $this->berkas($this->bytesPng(), 'baru.png'),
            'waktu_tiba' => '2026-11-02 10:04:00',
            'porsi_diterima' => 199,
            'catatan_selisih' => 'Satu porsi rusak',
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.terlambat', true)
            ->assertJsonPath('data.menit_keterlambatan', 19)
            ->assertJsonPath('data.foto.mime', 'image/png');

        $this->assertNotSame($fotoLama, $baru->json('data.foto.lampiran_id'));
        $this->assertCount(2, $baru->json('data.lampiran'));
        $this->assertTrue(AuditLog::query()->where('auditable_type', BuktiDistribusi::class)->where('auditable_id', $id)->where('event', 'updated')
            ->get()->contains(fn ($a) => ($a->old_values['porsi_diterima'] ?? null) == 200 && ($a->new_values['porsi_diterima'] ?? null) == 199));
    }

    public function test_baca_rbac_lintas_sppg_admin_dan_paginasi(): void
    {
        $gizi = $this->pengguna(Role::AhliGizi, $this->sppgA);
        $akuntan = $this->pengguna(Role::Akuntan, $this->sppgA);
        $gudang = $this->pengguna(Role::StafGudang, $this->sppgA);
        $admin = $this->pengguna(Role::Admin, $this->sppgA);
        $kepalaB = $this->pengguna(Role::KepalaSppg, $this->sppgB);

        foreach (range(1, 3) as $i) {
            BuktiDistribusi::factory()->create(['sppg_id' => $this->sppgA, 'jadwal_distribusi_id' => $this->jadwal()->id, 'terlambat' => $i === 1]);
        }
        $b = BuktiDistribusi::factory()->create(['sppg_id' => $this->sppgB, 'jadwal_distribusi_id' => $this->jadwal([], true, $this->sppgB)->id]);

        foreach ([$gizi, $akuntan, $this->kepala] as $u) {
            Sanctum::actingAs($u);
            $this->getJson('/api/v1/bukti-distribusi?per_page=2')->assertOk()->assertJsonCount(2, 'data')
                ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.per_page', 2);
        }

        Sanctum::actingAs($this->kepala);
        $this->getJson('/api/v1/bukti-distribusi?terlambat=1')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/bukti-distribusi/{$b->id}")->assertNotFound();
        $this->patchJson("/api/v1/bukti-distribusi/{$b->id}", ['nama_pic' => 'X'])->assertNotFound();
        $this->deleteJson("/api/v1/bukti-distribusi/{$b->id}")->assertNotFound();

        Sanctum::actingAs($gudang);
        $this->getJson('/api/v1/bukti-distribusi')->assertForbidden();

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/bukti-distribusi')->assertOk()->assertJsonPath('meta.total', 4);
        $this->getJson("/api/v1/bukti-distribusi?sppg_id={$this->sppgB}")->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("/api/v1/bukti-distribusi/{$b->id}")->assertOk();
        $this->deleteJson("/api/v1/bukti-distribusi/{$b->id}")->assertForbidden();

        Sanctum::actingAs($kepalaB);
        $this->getJson('/api/v1/bukti-distribusi')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_draf_ai_dikonfirmasi_dan_rollback_menghapus_berkas(): void
    {
        $jadwal = $this->jadwal();
        $drafLain = AiDraft::factory()->create(['sppg_id' => $this->sppgA, 'user_id' => $this->kepala->id, 'module' => 'bukti-distribusi']);
        $draf = AiDraft::factory()->create(['sppg_id' => $this->sppgA, 'user_id' => $this->kurir->id, 'module' => 'bukti-distribusi']);
        Sanctum::actingAs($this->kurir);

        $this->kirim($this->payload($jadwal, ['ai_draft_id' => $drafLain->uuid]))->assertUnprocessable()->assertJsonValidationErrors('ai_draft_id');
        $this->assertSame(0, BuktiDistribusi::query()->count());
        $this->assertSame(0, Lampiran::query()->count());
        $this->assertSame([], Storage::disk('uploads')->allFiles());
        $this->assertSame(StatusJadwalDistribusi::Siap, $jadwal->fresh()->status);

        $this->kirim($this->payload($jadwal, ['ai_draft_id' => $draf->uuid]))->assertCreated();
        $this->assertSame(StatusAiDraft::Confirmed, $draf->fresh()->status);
    }

    public function test_idempotency_key_tidak_menggandakan_bukti(): void
    {
        Event::fake([BuktiDistribusiDicatat::class]);
        $jadwal = $this->jadwal();
        Sanctum::actingAs($this->kurir);
        $kunci = (string) Str::uuid();
        $foto = $this->bytesJpeg(32, 32);
        $data = fn () => $this->payload($jadwal, ['foto' => $this->berkas($foto)]);

        $pertama = $this->kirim($data(), ['Idempotency-Key' => $kunci])->assertCreated();
        $kedua = $this->kirim($data(), ['Idempotency-Key' => $kunci])->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($pertama->json('data.id'), $kedua->json('data.id'));
        $this->assertSame(1, BuktiDistribusi::query()->count());
        $this->assertSame(1, Lampiran::query()->count());
        Event::assertDispatchedTimes(BuktiDistribusiDicatat::class, 1);

        $this->kirim($this->payload($jadwal, ['foto' => $this->berkas($this->bytesPng())]), ['Idempotency-Key' => $kunci])
            ->assertUnprocessable()->assertJsonValidationErrors('Idempotency-Key');
    }
}
