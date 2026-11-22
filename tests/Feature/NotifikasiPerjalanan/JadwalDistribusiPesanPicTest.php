<?php

namespace Tests\Feature\NotifikasiPerjalanan;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Enums\StatusJadwalDistribusi;
use App\Events\DistribusiDiterima;
use App\Jobs\SendTelegramNotification;
use App\Models\JadwalDistribusi;
use App\Models\NotifikasiPerjalanan;
use App\Models\User;
use App\Services\Perjalanan\TripNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Telegram\FakesTelegram;
use Tests\Support\GambarUji;
use Tests\Support\SkenarioOperasional;
use Tests\TestCase;

/**
 * Isu #177 (kasus uji 40 / P8): PATCH status jadwal distribusi dan notifikasi perjalanan tidak lagi dua jalur
 * terpisah. Berangkat -> estimasi tiba + pesan ke PIC; diterima (PATCH langsung) -> konfirmasi ke PIC; status kirim tampil di detail.
 * Pencatatan bukti distribusi TIDAK memicu pesan "diterima" (PIC cukup menerima "tercatat tiba").
 */
class JadwalDistribusiPesanPicTest extends TestCase
{
    use FakesTelegram, GambarUji, RefreshDatabase, SkenarioOperasional;

    private const PIC = 900000555;

    private int $sppgA;

    private int $sppgB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sppgA = $this->createSppg('SPPG A');
        $this->sppgB = $this->createSppg('SPPG B');
        Carbon::setTestNow(Carbon::parse('2026-11-02 08:30:00', 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function url(JadwalDistribusi $jadwal): string
    {
        return "/api/v1/jadwal/distribusi/{$jadwal->id}";
    }

    /** @return list<SendTelegramNotification> */
    private function pesanKe(int $chatId, ?string $jenis = null): array
    {
        return Queue::pushed(SendTelegramNotification::class, fn (SendTelegramNotification $j) => $j->chatId === $chatId
            && ($jenis === null || $j->type === $jenis))->all();
    }

    public function test_patch_berangkat_membuat_perjalanan_dan_mengirim_pesan_ke_pic_lewat_antrean(): void
    {
        Queue::fake();
        $kepala = $this->pengguna(Role::KepalaSppg, $this->sppgA);
        $jadwal = $this->jadwalSiap($this->sppgA, ['jarak_km' => 5, 'pic_telegram_chat_id' => self::PIC, 'nama' => 'SDN Contoh 3']);
        $this->masukPenuh($kepala);

        $this->patchJson($this->url($jadwal), ['status' => 'berangkat'])
            ->assertOk()
            ->assertJsonPath('data.status', 'berangkat')
            ->assertJsonPath('data.notifikasi_pic.jenis', 'berangkat')
            ->assertJsonPath('data.notifikasi_pic.status_kirim', 'menunggu')
            ->assertJsonPath('data.notifikasi_pic.dialihkan_ke_kepala', false);

        $n = NotifikasiPerjalanan::query()->where('jadwal_distribusi_id', $jadwal->id)->sole();
        $this->assertSame(self::PIC, $n->penerima_chat_id);
        $this->assertSame(15, $n->estimasi_menit);
        $this->assertSame('2026-11-02 08:45:00', $n->estimasi_tiba->format('Y-m-d H:i:s'));
        $this->assertSame($kepala->id, $n->dipicu_oleh);

        $pesan = $this->pesanKe(self::PIC, 'PerjalananDimulai');
        $this->assertCount(1, $pesan);
        $this->assertStringContainsString('SDN Contoh 3', $pesan[0]->html);
        $this->assertStringContainsString('estimasi tiba 08:45 WIB', $pesan[0]->html);
        $this->assertSame(['type' => 'notifikasi_perjalanan', 'id' => $n->id], $pesan[0]->track);
    }

    public function test_patch_berangkat_idempoten_bila_sudah_ada_perjalanan_aktif(): void
    {
        Queue::fake();
        $this->masukPenuh($this->pengguna(Role::KepalaSppg, $this->sppgA));
        $jadwal = $this->jadwalSiap($this->sppgA, ['pic_telegram_chat_id' => self::PIC]);

        // Pemicu notifikasi dulu (jadwal ikut berangkat), lalu status dipaksa kembali "siap" (data lama dua jalur).
        $this->postJson('/api/v1/notifikasi-perjalanan', ['jadwal_distribusi_id' => $jadwal->id])->assertCreated();
        $jadwal->forceFill(['status' => StatusJadwalDistribusi::Siap])->saveQuietly();

        $this->patchJson($this->url($jadwal), ['status' => 'berangkat'])->assertOk()->assertJsonPath('data.status', 'berangkat');
        $this->patchJson($this->url($jadwal), ['status' => 'berangkat'])->assertOk();

        $this->assertSame(1, NotifikasiPerjalanan::query()->where('jadwal_distribusi_id', $jadwal->id)->count());
        $this->assertCount(1, $this->pesanKe(self::PIC));
    }

    public function test_notifikasi_perjalanan_dari_jadwal_siap_mengubah_status_jadwal_tanpa_pesan_ganda(): void
    {
        Queue::fake();
        $this->masukPenuh($this->pengguna(Role::KepalaSppg, $this->sppgA));
        $jadwal = $this->jadwalSiap($this->sppgA, ['pic_telegram_chat_id' => self::PIC]);

        $this->postJson('/api/v1/notifikasi-perjalanan', ['jadwal_distribusi_id' => $jadwal->id])->assertCreated();

        $this->assertSame(StatusJadwalDistribusi::Berangkat, $jadwal->fresh()->status);
        $this->assertCount(1, $this->pesanKe(self::PIC, 'PerjalananDimulai'));

        // PATCH berangkat sesudahnya = tanpa perubahan: tidak ada perjalanan/pesan kedua.
        $this->patchJson($this->url($jadwal), ['status' => 'berangkat'])->assertOk();
        $this->assertSame(1, NotifikasiPerjalanan::query()->count());
        $this->assertCount(1, $this->pesanKe(self::PIC));

        $this->getJson($this->url($jadwal))->assertOk()
            ->assertJsonPath('data.status', 'berangkat')
            ->assertJsonPath('data.notifikasi_pic.jenis', 'berangkat')
            ->assertJsonPath('data.notifikasi_pic.status_kirim', 'menunggu');
    }

    public function test_status_diterima_mengirim_konfirmasi_singkat_ke_pic_dan_status_kirim_tampil_di_detail(): void
    {
        $this->fakeTelegram();
        $this->masukPenuh($this->pengguna(Role::KepalaSppg, $this->sppgA));
        $jadwal = $this->jadwalSiap($this->sppgA, ['pic_telegram_chat_id' => self::PIC, 'nama' => 'SDN Contoh 3']);

        $this->patchJson($this->url($jadwal), ['status' => 'berangkat'])
            ->assertOk()->assertJsonPath('data.notifikasi_pic.status_kirim', 'terkirim');

        Carbon::setTestNow(Carbon::parse('2026-11-02 09:10:00', 'Asia/Jakarta'));
        $this->patchJson($this->url($jadwal), ['status' => 'diterima'])
            ->assertOk()
            ->assertJsonPath('data.status', 'diterima')
            ->assertJsonPath('data.notifikasi_pic.jenis', 'diterima')
            // Respons PATCH mencerminkan keadaan saat commit (job antrean belum jalan); hasil kirim tampil di GET berikutnya.
            ->assertJsonPath('data.notifikasi_pic.status_kirim', 'menunggu')
            ->assertJsonPath('data.notifikasi_pic.riwayat.0.jenis', 'berangkat')
            ->assertJsonPath('data.notifikasi_pic.riwayat.1.jenis', 'diterima');

        $teks = $this->messagesTo(self::PIC)->map(fn ($r) => $r['text'])->all();
        $this->assertCount(2, $teks);
        $this->assertStringContainsString('berangkat menuju SDN Contoh 3', $teks[0]);
        $this->assertStringContainsString('SDN Contoh 3', $teks[1]);
        $this->assertStringContainsString('diterima pukul 09:10 WIB', $teks[1]);
        // Tanpa data pribadi berlebih: nama/telepon PIC tidak ikut di pesan.
        $this->assertStringNotContainsString('0812', $teks[1]);

        $this->getJson($this->url($jadwal))->assertOk()
            ->assertJsonPath('data.notifikasi_pic.jenis', 'diterima')
            ->assertJsonPath('data.notifikasi_pic.status_kirim', 'terkirim');
    }

    public function test_pic_belum_terhubung_dialihkan_ke_kepala_sppg_untuk_berangkat_dan_diterima(): void
    {
        Queue::fake();
        $kepala = $this->pengguna(Role::KepalaSppg, $this->sppgA);
        $this->masukPenuh($kepala);
        $jadwal = $this->jadwalSiap($this->sppgA, ['pic_telegram_chat_id' => null]);

        $this->patchJson($this->url($jadwal), ['status' => 'berangkat'])
            ->assertOk()
            ->assertJsonPath('data.notifikasi_pic.jenis', 'berangkat')
            ->assertJsonPath('data.notifikasi_pic.status_kirim', 'dialihkan_ke_kepala')
            ->assertJsonPath('data.notifikasi_pic.dialihkan_ke_kepala', true);

        $n = NotifikasiPerjalanan::query()->sole();
        $this->assertTrue($n->dialihkan_ke_kepala);
        $this->assertCount(1, $this->pesanKe((int) $kepala->telegram_id, 'PerjalananDimulai'));

        $this->patchJson($this->url($jadwal), ['status' => 'diterima'])
            ->assertOk()
            ->assertJsonPath('data.notifikasi_pic.jenis', 'diterima')
            ->assertJsonPath('data.notifikasi_pic.status_kirim', 'dialihkan_ke_kepala');

        $this->assertCount(1, $this->pesanKe((int) $kepala->telegram_id, 'DistribusiDiterima'));
    }

    public function test_aturan_petugas_f02_berlaku_di_jalur_patch(): void
    {
        Queue::fake();
        $petugas = $this->pengguna(Role::Relawan, $this->sppgA, RelawanUnit::Distribusi);
        $lain = $this->pengguna(Role::Relawan, $this->sppgA, RelawanUnit::Distribusi);
        $jadwal = $this->jadwalSiap($this->sppgA, ['pic_telegram_chat_id' => self::PIC], ['petugas_id' => $petugas->id, 'dibuat_oleh' => $lain->id]);

        $this->masukPenuh($lain);
        $this->patchJson($this->url($jadwal), ['status' => 'berangkat'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertSame(StatusJadwalDistribusi::Siap, $jadwal->fresh()->status);
        $this->assertSame(0, NotifikasiPerjalanan::query()->count());
        Queue::assertNothingPushed();

        // Petugas yang ditugaskan (pembuat jadwal) boleh.
        $milik = $this->jadwalSiap($this->sppgA, ['pic_telegram_chat_id' => self::PIC], ['petugas_id' => $petugas->id, 'dibuat_oleh' => $petugas->id]);
        $this->masukPenuh($petugas);
        $this->patchJson($this->url($milik), ['status' => 'berangkat'])->assertOk()->assertJsonPath('data.status', 'berangkat');
        $this->assertCount(1, $this->pesanKe(self::PIC, 'PerjalananDimulai'));
    }

    public function test_gerbang_mutu_menahan_berangkat_di_kedua_jalur_tanpa_pesan(): void
    {
        Queue::fake();
        $this->masukPenuh($this->pengguna(Role::KepalaSppg, $this->sppgA));
        $jadwal = $this->jadwalSiap($this->sppgA, ['pic_telegram_chat_id' => self::PIC], layak: false);

        $this->patchJson($this->url($jadwal), ['status' => 'berangkat'])->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->postJson('/api/v1/notifikasi-perjalanan', ['jadwal_distribusi_id' => $jadwal->id])->assertUnprocessable();

        $this->assertSame(StatusJadwalDistribusi::Siap, $jadwal->fresh()->status);
        $this->assertSame(0, NotifikasiPerjalanan::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_tanpa_hak_403_dan_lintas_sppg_tidak_dapat_diakses(): void
    {
        Queue::fake();
        $jadwalA = $this->jadwalSiap($this->sppgA, ['pic_telegram_chat_id' => self::PIC]);
        $jadwalB = $this->jadwalSiap($this->sppgB, ['pic_telegram_chat_id' => self::PIC + 1]);

        $this->masukPenuh($this->pengguna(Role::Akuntan, $this->sppgA));
        $this->patchJson($this->url($jadwalA), ['status' => 'berangkat'])->assertForbidden();

        $this->masukPenuh($this->pengguna(Role::KepalaSppg, $this->sppgA));
        $this->patchJson($this->url($jadwalB), ['status' => 'berangkat'])->assertNotFound();
        $this->getJson($this->url($jadwalB))->assertNotFound();

        $this->assertSame(0, NotifikasiPerjalanan::query()->withoutGlobalScopes()->count());
        Queue::assertNothingPushed();
    }

    /** @return array<string, mixed> */
    private function payloadBukti(JadwalDistribusi $jadwal): array
    {
        return [
            'jadwal_distribusi_id' => $jadwal->id,
            'porsi_diterima' => (int) $jadwal->jumlah_porsi,
            'nama_pic' => 'Ibu Contoh',
            'foto' => $this->fotoJpeg(),
        ];
    }

    public function test_bukti_distribusi_tidak_memicu_pesan_diterima_hanya_tercatat_tiba_ke_pic(): void
    {
        Storage::fake('uploads');
        $this->fakeTelegram();
        $this->masukPenuh($this->pengguna(Role::KepalaSppg, $this->sppgA));
        $jadwal = $this->jadwalSiap($this->sppgA, ['pic_telegram_chat_id' => self::PIC, 'nama' => 'SDN Contoh 4'], ['tanggal_distribusi' => '2026-11-02']);

        $this->patchJson($this->url($jadwal), ['status' => 'berangkat'])->assertOk();

        Event::fake([DistribusiDiterima::class]);
        Carbon::setTestNow(Carbon::parse('2026-11-02 09:10:00', 'Asia/Jakarta'));
        $this->post('/api/v1/bukti-distribusi', $this->payloadBukti($jadwal), ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.jadwal_distribusi.status', 'diterima');

        Event::assertNotDispatched(DistribusiDiterima::class);
        $jadwal->refresh();
        $this->assertSame(StatusJadwalDistribusi::Diterima, $jadwal->status);
        $this->assertNull($jadwal->notif_diterima_status);

        $teks = $this->messagesTo(self::PIC)->map(fn ($r) => $r['text'])->all();
        $this->assertCount(2, $teks);
        $this->assertStringContainsString('berangkat menuju SDN Contoh 4', $teks[0]);
        $this->assertStringContainsString('tercatat tiba di SDN Contoh 4', $teks[1]);
        $this->assertStringNotContainsString('diterima pukul', implode("\n", $teks));

        // notifikasi_pic: hanya entri "berangkat" (terkirim), tanpa entri "diterima" palsu dan tanpa "menunggu" menggantung.
        $this->getJson($this->url($jadwal))->assertOk()
            ->assertJsonPath('data.status', 'diterima')
            ->assertJsonPath('data.notifikasi_pic.jenis', 'berangkat')
            ->assertJsonPath('data.notifikasi_pic.status_kirim', 'terkirim')
            ->assertJsonCount(1, 'data.notifikasi_pic.riwayat');

        // PATCH diterima sesudah bukti = tanpa perubahan status, tetap tidak ada pesan "diterima".
        $this->patchJson($this->url($jadwal), ['status' => 'diterima']);
        $this->assertNull($jadwal->fresh()->notif_diterima_status);
    }

    public function test_bukti_distribusi_tanpa_perjalanan_tidak_mengirim_pesan_dan_notifikasi_pic_null(): void
    {
        Storage::fake('uploads');
        Queue::fake();
        $this->masukPenuh($this->pengguna(Role::KepalaSppg, $this->sppgA));
        $jadwal = $this->jadwalSiap($this->sppgA, ['pic_telegram_chat_id' => self::PIC], ['tanggal_distribusi' => '2026-11-02']);

        $this->post('/api/v1/bukti-distribusi', $this->payloadBukti($jadwal), ['Accept' => 'application/json'])->assertCreated();

        $this->assertNull($jadwal->fresh()->notif_diterima_status);
        $this->assertCount(0, $this->pesanKe(self::PIC, 'DistribusiDiterima'));
        $this->getJson($this->url($jadwal))->assertOk()
            ->assertJsonPath('data.status', 'diterima')
            ->assertJsonPath('data.notifikasi_pic', null);
    }

    public function test_patch_diterima_langsung_mengantre_pesan_diterima_tetapi_bukti_tidak(): void
    {
        Queue::fake();
        $this->masukPenuh($this->pengguna(Role::KepalaSppg, $this->sppgA));
        $jadwal = $this->jadwalSiap($this->sppgA, ['pic_telegram_chat_id' => self::PIC]);
        $this->patchJson($this->url($jadwal), ['status' => 'berangkat'])->assertOk();

        $this->patchJson($this->url($jadwal), ['status' => 'diterima'])->assertOk();

        $this->assertCount(1, $this->pesanKe(self::PIC, 'DistribusiDiterima'));
    }

    public function test_berangkat_ditolak_422_bila_titik_layanan_terhapus_lunak_tanpa_perubahan_dan_pesan(): void
    {
        Queue::fake();
        $this->masukPenuh($this->pengguna(Role::KepalaSppg, $this->sppgA));
        $jadwal = $this->jadwalSiap($this->sppgA, ['pic_telegram_chat_id' => self::PIC]);
        $jadwal->titikLayanan()->first()->delete();

        $this->patchJson($this->url($jadwal), ['status' => 'berangkat'])
            ->assertUnprocessable()->assertJsonValidationErrors(['jadwal_distribusi_id']);
        $this->postJson('/api/v1/notifikasi-perjalanan', ['jadwal_distribusi_id' => $jadwal->id])->assertUnprocessable();

        $this->assertSame(StatusJadwalDistribusi::Siap, $jadwal->fresh()->status);
        $this->assertSame(0, NotifikasiPerjalanan::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_berangkat_ditolak_422_bila_batch_produksi_terhapus_lunak_tanpa_perubahan_dan_pesan(): void
    {
        Queue::fake();
        $this->masukPenuh($this->pengguna(Role::KepalaSppg, $this->sppgA));
        $jadwal = $this->jadwalSiap($this->sppgA, ['pic_telegram_chat_id' => self::PIC]);
        $jadwal->jadwalProduksi()->first()->delete();

        $this->patchJson($this->url($jadwal), ['status' => 'berangkat'])
            ->assertUnprocessable()->assertJsonValidationErrors(['jadwal_produksi_id']);
        $this->postJson('/api/v1/notifikasi-perjalanan', ['jadwal_distribusi_id' => $jadwal->id])->assertUnprocessable();

        $this->assertSame(StatusJadwalDistribusi::Siap, $jadwal->fresh()->status);
        $this->assertSame(0, NotifikasiPerjalanan::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_konfirmasi_diterima_idempoten_per_jadwal(): void
    {
        Event::fake([DistribusiDiterima::class]);
        $jadwal = $this->jadwalSiap($this->sppgA, ['pic_telegram_chat_id' => self::PIC]);
        $jadwal->forceFill(['status' => StatusJadwalDistribusi::Diterima])->saveQuietly();
        $service = app(TripNotificationService::class);

        $service->konfirmasiDiterima($jadwal->fresh());
        $service->konfirmasiDiterima($jadwal->fresh());

        Event::assertDispatchedTimes(DistribusiDiterima::class, 1);
        $this->assertSame('menunggu', $jadwal->fresh()->notif_diterima_status);
        $this->assertInstanceOf(User::class, $this->pengguna(Role::KepalaSppg, $this->sppgA));
    }
}
