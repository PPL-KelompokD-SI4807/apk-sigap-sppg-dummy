<?php

namespace Tests\Feature\Telegram\Bot;

use App\Enums\StatusKirim;
use App\Events\PerjalananDimulai;
use App\Events\RekapHarianDibuat;
use App\Jobs\SendTelegramNotification;
use App\Models\AuditLog;
use App\Models\BotAksi;
use App\Services\Telegram\Bot\BotAksiTulisService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

/**
 * Temuan E2-3: aksi tulis dengan listener notifikasi ASLI (event domain tidak di-fake). Hanya job SendTelegramNotification
 * yang di-fake (QueueFake mencatat job seketika, mengabaikan afterCommit job). Bila jejak audit gagal: tidak ada event yang
 * sampai ke listener, tidak ada job notifikasi, tidak ada baris perjalanan, rekap tidak berubah, jeda rekap dilepas. Tes kontrol
 * membuktikan jalur yang sama menjadwalkan job saat berhasil (tidak vakum).
 *
 * Catatan: PerjalananDimulai dan RekapHarianDibuat ber-ShouldDispatchAfterCommit, jadi rollback sudah mencegah notifikasi
 * apa pun urutan auditnya; urutan audit-sebelum-event (TripNotificationService::mulai setelahSimpan) adalah pertahanan
 * berlapis bila kelak event tidak lagi after-commit, dan tidak dapat dibedakan oleh tes ini.
 */
class BotAksiTulisNotifikasiAsliTest extends AksiTulisTestCase
{
    protected bool $fakeEventDomain = false;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([SendTelegramNotification::class]);
        Cache::flush();
    }

    /** @var array<string, int> */
    private array $dipancarkan = [];

    /** Hitung event domain yang BENAR-BENAR dipancarkan (listener notifikasi asli tetap berjalan). */
    private function hitungEvent(): void
    {
        foreach ([PerjalananDimulai::class, RekapHarianDibuat::class] as $kelas) {
            Event::listen($kelas, function () use ($kelas): void {
                $this->dipancarkan[$kelas] = ($this->dipancarkan[$kelas] ?? 0) + 1;
            });
        }
    }

    private function auditGagal(): void
    {
        $this->hitungEvent();
        AuditLog::creating(function (): void {
            throw new RuntimeException('audit gagal');
        });
    }

    private function bukaKonfirmasiRekap(): string
    {
        $this->sesiPenuh($this->kepalaA);
        $this->keKonfirmasi($this->kepalaA, 'rk', 'Kirim ulang rekap');

        return $this->ya($this->kepalaA);
    }

    private function bukaKonfirmasiPerjalanan(): string
    {
        $this->sesiPenuh($this->kepalaA);
        $this->keKonfirmasi($this->kepalaA, 'mp', 'Berangkat:');

        return $this->ya($this->kepalaA);
    }

    public function test_kontrol_kirim_ulang_rekap_menjadwalkan_notifikasi_asli(): void
    {
        $rekap = $this->rekap($this->sppgA);
        $this->tekan($this->kepalaA, $this->bukaKonfirmasiRekap());

        $this->assertSame(StatusKirim::Menunggu, $this->rekapRaw($rekap->id)->status_kirim);
        Queue::assertPushed(SendTelegramNotification::class);
    }

    public function test_audit_gagal_saat_kirim_ulang_rekap_tanpa_notifikasi_dan_rekap_tidak_berubah(): void
    {
        $rekap = $this->rekap($this->sppgA);
        $ya = $this->bukaKonfirmasiRekap();
        $this->auditGagal();

        $this->tekan($this->kepalaA, $ya);

        Queue::assertNotPushed(SendTelegramNotification::class);
        $this->assertSame([], $this->dipancarkan, 'Rollback: event after-commit tidak pernah sampai ke listener');
        $segar = $this->rekapRaw($rekap->id);
        $this->assertSame(StatusKirim::Terkirim, $segar->status_kirim);
        $this->assertNotNull($segar->dikirim_pada);
        $this->assertFalse(Cache::has('bot:kirim-ulang-rekap:'.$rekap->id), 'Jeda dilepas: tidak ada yang terkirim');
        $this->assertStringContainsString('Terjadi kesalahan', $this->teksLayar($this->kepalaA));
        $this->assertSame('gagal', BotAksi::query()->where('kode', explode('.', $ya)[1])->value('hasil'));
    }

    public function test_kontrol_mulai_perjalanan_menjadwalkan_notifikasi_asli_ke_pic(): void
    {
        $jadwal = $this->jadwalSiap($this->sppgA, ['pic_telegram_chat_id' => 900000888]);
        $this->tekan($this->kepalaA, $this->bukaKonfirmasiPerjalanan());

        $this->assertSame(1, $this->jumlahPerjalanan($jadwal->id));
        Queue::assertPushed(SendTelegramNotification::class, fn (SendTelegramNotification $j) => $j->chatId === 900000888);
    }

    public function test_audit_gagal_saat_mulai_perjalanan_tanpa_notifikasi_dan_tanpa_baris(): void
    {
        $jadwal = $this->jadwalSiap($this->sppgA, ['pic_telegram_chat_id' => 900000888]);
        $ya = $this->bukaKonfirmasiPerjalanan();
        $this->auditGagal();

        $this->tekan($this->kepalaA, $ya);

        Queue::assertNotPushed(SendTelegramNotification::class);
        $this->assertSame([], $this->dipancarkan, 'Rollback: event after-commit tidak pernah sampai ke listener');
        $this->assertSame(0, $this->jumlahPerjalanan($jadwal->id));
        $this->assertStringContainsString('Terjadi kesalahan', $this->teksLayar($this->kepalaA));
        $this->assertStringNotContainsString(BotAksiTulisService::PESAN_SUDAH_DIPROSES, $this->teksLayar($this->kepalaA));

        // Setelah audit pulih, perjalanan tetap bisa dimulai (dedup notifikasi tidak "terpakai" oleh percobaan yang gagal).
        AuditLog::flushEventListeners();
        $this->dipancarkan = [];
        $this->tekan($this->kepalaA, $this->bukaKonfirmasiPerjalanan());
        $this->assertSame(1, $this->jumlahPerjalanan($jadwal->id));
        Queue::assertPushed(SendTelegramNotification::class, fn (SendTelegramNotification $j) => $j->chatId === 900000888);
    }
}
