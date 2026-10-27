<?php

namespace Tests\Feature\Telegram\Bot;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Enums\StatusKirim;
use App\Events\PerjalananDimulai;
use App\Events\RekapHarianDibuat;
use App\Models\AuditLog;
use App\Models\BotAksi;
use App\Models\JadwalDistribusi;
use App\Models\NotifikasiPerjalanan;
use App\Models\PemeriksaanMutu;
use App\Models\Scopes\SppgScope;
use App\Models\User;
use App\Services\Perjalanan\TripNotificationService;
use App\Services\Telegram\Bot\BotAksiTulisService;
use App\Services\Telegram\Bot\BotCallbackHandler;
use App\Services\Telegram\TelegramBotService;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Alur aksi tulis berkonfirmasi Bot (R-02 PR-3): kirim ulang rekap, mulai perjalanan, tindak lanjut keluhan.
 * Layar baca -> tombol aksi `b1` -> konfirmasi (Ya, lakukan / Batal) -> satu pemanggilan service -> layar hasil.
 */
class BotAksiTulisTest extends AksiTulisTestCase
{
    // ------------------------------------------------------------------ kirim ulang rekap

    public function test_kirim_ulang_rekap_dua_langkah_sukses_dengan_audit_bot(): void
    {
        $hari = $this->rekap($this->sppgA);
        $this->rekap($this->sppgA, $this->kemarin);
        $this->rekap($this->sppgA, now()->subDays(3)->toDateString());
        $this->sesiPenuh($this->kepalaA);

        $this->buka($this->kepalaA, 'rk');
        $label = array_column($this->tombolLayar((int) $this->kepalaA->telegram_id), 'text');
        $this->assertCount(2, array_filter($label, fn ($t) => str_starts_with($t, 'Kirim ulang rekap')), 'Hanya hari ini dan kemarin');

        $aksi = $this->keKonfirmasi($this->kepalaA, 'rk', 'Kirim ulang rekap '.now(config('operasional.timezone'))->format('d/m'));
        $teks = $this->teksLayar($this->kepalaA);
        $this->assertStringContainsString('Status kirim saat ini: Terkirim', $teks);
        $this->assertStringContainsString('tidak dapat ditarik kembali', $teks);
        Event::assertNotDispatched(RekapHarianDibuat::class);
        $this->assertSame(BotAksi::STATUS_SELESAI, $this->statusToken($aksi));

        $ya = $this->ya($this->kepalaA);
        $this->tekan($this->kepalaA, $ya);

        $this->assertStringContainsString('Rekap dikirim ulang', $this->teksLayar($this->kepalaA));
        $segar = $this->rekapRaw($hari->id);
        $this->assertSame(StatusKirim::Menunggu, $segar->status_kirim);
        $this->assertNull($segar->dikirim_pada);
        Event::assertDispatchedTimes(RekapHarianDibuat::class, 1);
        Event::assertDispatched(RekapHarianDibuat::class, fn (RekapHarianDibuat $e) => $e->id === $hari->id);

        $audit = AuditLog::query()->withoutGlobalScopes()->where('event', 'bot_kirim_ulang_rekap')->sole();
        $this->assertSame((int) $this->kepalaA->id, $audit->user_id);
        $this->assertSame('bot', $audit->sumber);
        $this->assertSame($hari->id, $audit->auditable_id);

        // Tekan ganda / replay "Ya": tidak menggandakan kiriman.
        $this->tekan($this->kepalaA, $ya);
        $this->assertSame(BotCallbackHandler::TIDAK_BERLAKU, $this->jawabanTerakhir());
        Event::assertDispatchedTimes(RekapHarianDibuat::class, 1);
    }

    public function test_kirim_ulang_rekap_dibatasi_satu_kali_per_lima_menit(): void
    {
        $rekap = $this->rekap($this->sppgA);
        $this->sesiPenuh($this->kepalaA);

        $this->keKonfirmasi($this->kepalaA, 'rk', 'Kirim ulang rekap');
        $this->tekan($this->kepalaA, $this->ya($this->kepalaA));
        Event::assertDispatchedTimes(RekapHarianDibuat::class, 1);

        // Status kini "menunggu" (sedang dikirim): tanpa tombol kirim ulang (E2-2).
        $this->buka($this->kepalaA, 'rk');
        $this->assertFalse($this->adaTombol($this->kepalaA, 'Kirim ulang rekap'));

        // Listener selesai mengirim (status terkirim): tombol muncul lagi, tetapi konfirmasi menolak selama jeda.
        $this->travel(1)->seconds();
        $this->tandaiTerkirim($rekap->id);
        $this->buka($this->kepalaA, 'rk');
        $this->tekan($this->kepalaA, $this->dataTombol($this->kepalaA, 'Kirim ulang rekap'));
        $this->assertStringContainsString('baru saja dikirim ulang', $this->teksLayar($this->kepalaA));
        $this->assertFalse($this->adaTombol($this->kepalaA, BotAksiTulisService::LABEL_YA));

        // Penjaga atomik di langkah "Ya" (mis. dua layar konfirmasi terbuka bersamaan).
        Cache::forget('bot:kirim-ulang-rekap:'.$rekap->id);
        $this->keKonfirmasi($this->kepalaA, 'rk', 'Kirim ulang rekap');
        Cache::add('bot:kirim-ulang-rekap:'.$rekap->id, 1, 300);
        $this->tekan($this->kepalaA, $this->ya($this->kepalaA));
        $this->assertStringContainsString('baru saja dikirim ulang', $this->teksLayar($this->kepalaA));
        Event::assertDispatchedTimes(RekapHarianDibuat::class, 1);

        // Jeda berlalu: boleh lagi.
        $this->travel(6)->minutes();
        $this->keKonfirmasi($this->kepalaA, 'rk', 'Kirim ulang rekap');
        $this->tekan($this->kepalaA, $this->ya($this->kepalaA));
        Event::assertDispatchedTimes(RekapHarianDibuat::class, 2);
    }

    /** E2-2: rekap sudah "menunggu" + dikirim_pada null -> save() tidak mengubah apa pun; dua konfirmasi tidak boleh sama-sama lolos. */
    public function test_rekap_menunggu_ditolak_ramah_dan_dua_konfirmasi_tidak_dua_kali_kirim(): void
    {
        $rekap = $this->rekap($this->sppgA);
        $this->sesiPenuh($this->kepalaA);

        $this->keKonfirmasi($this->kepalaA, 'rk', 'Kirim ulang rekap');
        $yaSatu = $this->ya($this->kepalaA);
        $this->keKonfirmasi($this->kepalaA, 'rk', 'Kirim ulang rekap');
        $yaDua = $this->ya($this->kepalaA);
        $this->assertNotSame($yaSatu, $yaDua);

        $this->tekan($this->kepalaA, $yaSatu);
        Cache::flush(); // tanpa penjaga jeda sekalipun, keadaan "menunggu"/versi menolak konfirmasi kedua
        $this->tekan($this->kepalaA, $yaDua);
        $this->assertNotSame('Rekap dikirim ulang', $this->teksLayar($this->kepalaA));
        Event::assertDispatchedTimes(RekapHarianDibuat::class, 1);

        // Rekap sudah "menunggu" dengan dikirim_pada null (kirim ulang tidak mengubah baris): token dengan versi persis
        // keadaan saat ini ditolak tepat karena SEDANG_DIKIRIM.
        DB::table('rekap_harians')->where('id', $rekap->id)->update(['status_kirim' => 'menunggu', 'dikirim_pada' => null]);
        Cache::flush();
        $versi = $this->versiAsli(BotAksi::JENIS_KIRIM_ULANG_REKAP, $rekap);

        foreach ([BotAksi::JENIS_KIRIM_ULANG_REKAP, BotAksi::JENIS_YA_KIRIM_ULANG_REKAP] as $jenis) {
            $this->tekan($this->kepalaA, $this->token($this->kepalaA, $jenis, $this->opsi($this->sppgA, BotAksi::SUBJEK_REKAP, $rekap->id, $versi)));
            $this->assertSame('Aksi tidak lagi tersedia.', $this->jawabanTerakhir(), $jenis);
            $this->assertStringContainsString('sedang dikirim (status Menunggu)', $this->teksLayar($this->kepalaA), $jenis);
        }

        Event::assertDispatchedTimes(RekapHarianDibuat::class, 1);

        // REST tidak berubah: kirim ulang rekap "menunggu" tetap diterima.
        $this->as($this->kepalaA);
        $this->postJson("/api/v1/rekap-harian/{$rekap->id}/kirim-ulang")->assertOk();
        Event::assertDispatchedTimes(RekapHarianDibuat::class, 2);
    }

    public function test_rekap_di_luar_hari_ini_dan_kemarin_ditolak_ramah(): void
    {
        $lama = $this->rekap($this->sppgA, now()->subDays(3)->toDateString());
        $this->sesiPenuh($this->kepalaA);

        $this->buka($this->kepalaA, 'rk');
        $this->assertFalse($this->adaTombol($this->kepalaA, 'Kirim ulang rekap'));

        // Token dengan versi persis keadaan saat ini: ditolak tepat karena jendela (bukan versi); tanpa efek.
        $this->tekan($this->kepalaA, $this->token($this->kepalaA, BotAksi::JENIS_KIRIM_ULANG_REKAP, $this->opsi($this->sppgA, BotAksi::SUBJEK_REKAP, $lama->id, $this->versiAsli(BotAksi::JENIS_KIRIM_ULANG_REKAP, $lama))));
        $this->assertSame('Aksi tidak lagi tersedia.', $this->jawabanTerakhir());
        $this->assertStringContainsString('hanya untuk rekap hari ini dan kemarin', $this->teksLayar($this->kepalaA));
        $this->assertTrue($this->adaTombol($this->kepalaA, 'Muat ulang'));
        Event::assertNotDispatched(RekapHarianDibuat::class);
        $this->assertSame(StatusKirim::Terkirim, $this->rekapRaw($lama->id)->status_kirim);
    }

    private function tandaiTerkirim(int $rekapId): void
    {
        DB::table('rekap_harians')->where('id', $rekapId)->update(['status_kirim' => 'terkirim', 'dikirim_pada' => now(), 'updated_at' => now()]);
    }

    public function test_rest_kirim_ulang_tidak_berubah_dan_tidak_terkena_jeda_bot(): void
    {
        $rekap = $this->rekap($this->sppgA);
        $this->as($this->kepalaA);

        $this->postJson("/api/v1/rekap-harian/{$rekap->id}/kirim-ulang")->assertOk()->assertJsonPath('data.status_kirim', 'menunggu')->assertJsonPath('data.dikirim_pada', null);
        $this->postJson("/api/v1/rekap-harian/{$rekap->id}/kirim-ulang")->assertOk();
        Event::assertDispatchedTimes(RekapHarianDibuat::class, 2);

        $this->as($this->ahliGiziA);
        $this->postJson("/api/v1/rekap-harian/{$rekap->id}/kirim-ulang")->assertForbidden();
        $this->as($this->kepalaB);
        $this->postJson("/api/v1/rekap-harian/{$rekap->id}/kirim-ulang")->assertNotFound();
        Event::assertDispatchedTimes(RekapHarianDibuat::class, 2);
    }

    // ------------------------------------------------------------------ mulai perjalanan

    public function test_kepala_mulai_perjalanan_dengan_pratinjau_penerima_dan_estimasi(): void
    {
        $jadwal = $this->jadwalSiap($this->sppgA, ['nama' => 'SD Tujuan Alfa', 'pic_telegram_chat_id' => 900000777]);
        $this->sesiPenuh($this->kepalaA);

        $this->keKonfirmasi($this->kepalaA, 'mp', 'Berangkat: SD Tujuan Alfa');
        $teks = $this->teksLayar($this->kepalaA);
        $this->assertStringContainsString('Tujuan: SD Tujuan Alfa', $teks);
        $this->assertStringContainsString('Estimasi tiba:', $teks);
        $this->assertStringContainsString('15 menit', $teks, '5 km / 30 km/jam + buffer 5');
        $this->assertStringContainsString('PIC titik layanan (Telegram)', $teks);
        $this->assertStringNotContainsString('900000777', $teks, 'Chat id PIC tidak tampil');
        $this->assertSame(0, $this->jumlahPerjalanan($jadwal->id), 'Pratinjau tidak menyimpan');
        Event::assertNotDispatched(PerjalananDimulai::class);

        $ya = $this->ya($this->kepalaA);
        $this->tekan($this->kepalaA, $ya);
        $this->tekan($this->kepalaA, $ya);

        $this->assertSame(1, $this->jumlahPerjalanan($jadwal->id));
        Event::assertDispatchedTimes(PerjalananDimulai::class, 1);
        $n = NotifikasiPerjalanan::query()->withoutGlobalScope(SppgScope::class)->sole();
        $this->assertSame((int) $this->kepalaA->id, (int) $n->dipicu_oleh);
        $this->assertSame($this->sppgA, (int) $n->sppg_id);

        $audit = AuditLog::query()->withoutGlobalScopes()->where('event', 'bot_picu_perjalanan')->sole();
        $this->assertSame('bot', $audit->sumber);
        $this->assertSame((int) $this->kepalaA->id, $audit->user_id);
        $this->assertStringNotContainsString('900000777', json_encode([$audit->old_values, $audit->new_values]));
        $this->assertTanpaBocor();
    }

    public function test_relawan_distribusi_mulai_perjalanan_pic_belum_terhubung_dialihkan_ke_kepala(): void
    {
        $jadwal = $this->jadwalSiap($this->sppgA);
        $kurir = $this->relawanDistribusi();
        $this->sesiPenuh($kurir);

        $this->keKonfirmasi($kurir, 'mp', 'Berangkat:');
        $this->assertStringContainsString('Kepala SPPG (PIC titik layanan belum terhubung)', $this->teksLayar($kurir));
        $this->tekan($kurir, $this->ya($kurir));

        $this->assertStringContainsString('Perjalanan dimulai', $this->teksLayar($kurir));
        $this->assertStringContainsString('dialihkan ke Kepala SPPG', $this->teksLayar($kurir));
        $this->assertSame(1, $this->jumlahPerjalanan($jadwal->id));
        $this->assertSame((int) $kurir->id, (int) NotifikasiPerjalanan::query()->withoutGlobalScope(SppgScope::class)->value('dipicu_oleh'));
    }

    public function test_jadwal_tidak_layak_atau_sudah_berjalan_tampil_ramah_tanpa_tombol(): void
    {
        $this->jadwalSiap($this->sppgA, ['nama' => 'SD Belum Layak'], layak: false);
        $berjalan = $this->jadwalSiap($this->sppgA, ['nama' => 'SD Sudah Jalan']);
        NotifikasiPerjalanan::factory()->create(['sppg_id' => $this->sppgA, 'jadwal_distribusi_id' => $berjalan->id]);
        $this->jadwalSiap($this->sppgA, ['nama' => 'SD Besok'], tanggal: now()->addDay()->toDateString());
        $this->sesiPenuh($this->kepalaA);

        $teks = (string) $this->buka($this->kepalaA, 'mp');
        $this->assertStringContainsString('SD Belum Layak: Hasil pemeriksaan mutu batch produksi belum Layak', $teks);
        $this->assertStringContainsString('SD Sudah Jalan: Sudah ada perjalanan aktif', $teks);
        $this->assertStringNotContainsString('SD Besok', $teks, 'Hanya jadwal hari ini');
        $this->assertFalse($this->adaTombol($this->kepalaA, 'Berangkat:'));
        $this->assertSame(0, BotAksi::query()->count());
    }

    public function test_penolakan_service_saat_berangkat_menjadi_pesan_ramah_bukan_galat(): void
    {
        $jadwal = $this->jadwalSiap($this->sppgA);
        $this->sesiPenuh($this->kepalaA);
        $this->keKonfirmasi($this->kepalaA, 'mp', 'Berangkat:');

        // Balapan: perjalanan lain lolos lebih dulu di dalam service (meniru penolakan perjalanan ganda).
        $this->app->instance(TripNotificationService::class, new class extends TripNotificationService
        {
            public function mulai(User $pemicu, JadwalDistribusi $jadwal, ?CarbonInterface $berangkat = null, ?int $manualMenit = null, ?Closure $setelahSimpan = null): NotifikasiPerjalanan
            {
                throw ValidationException::withMessages(['jadwal_distribusi_id' => ['Sudah ada perjalanan aktif untuk jadwal distribusi ini.']]);
            }
        });
        $this->tekan($this->kepalaA, $this->ya($this->kepalaA));

        $this->assertStringContainsString('Sudah ada perjalanan aktif untuk jadwal distribusi ini.', $this->teksLayar($this->kepalaA));
        $this->assertStringNotContainsString('Terjadi kesalahan', $this->teksLayar($this->kepalaA));
        $this->assertSame(0, $this->jumlahPerjalanan($jadwal->id));
        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()->where('event', 'bot_picu_perjalanan')->count());
        $this->assertFalse(collect($this->logBot)->contains(fn ($l) => $l['message'] === 'bot.galat'));
    }

    public function test_mutu_berubah_tidak_layak_setelah_konfirmasi_data_sudah_berubah(): void
    {
        $jadwal = $this->jadwalSiap($this->sppgA);
        $this->sesiPenuh($this->kepalaA);
        $this->keKonfirmasi($this->kepalaA, 'mp', 'Berangkat:');

        $this->travel(1)->minutes();
        PemeriksaanMutu::factory()->tidakLayak()->create(['sppg_id' => $this->sppgA, 'jadwal_produksi_id' => $jadwal->jadwal_produksi_id, 'waktu_pemeriksaan' => now()]);
        $this->tekan($this->kepalaA, $this->ya($this->kepalaA));

        $this->assertStringContainsString(BotAksiTulisService::PESAN_BERUBAH, $this->teksLayar($this->kepalaA));
        $this->assertSame(0, $this->jumlahPerjalanan($jadwal->id));
        Event::assertNotDispatched(PerjalananDimulai::class);
    }

    /** E2-1: perjalanan dimulai lalu dibatalkan (orang lain) di antara konfirmasi dan "Ya": "Ya" basi ditolak, tanpa notifikasi kedua. */
    public function test_mulai_lalu_batal_di_antara_konfirmasi_dan_ya_data_sudah_berubah(): void
    {
        $jadwal = $this->jadwalSiap($this->sppgA);
        $kurir = $this->relawanDistribusi();
        $this->sesiPenuh($this->kepalaA);
        $this->keKonfirmasi($this->kepalaA, 'mp', 'Berangkat:');
        $ya = $this->ya($this->kepalaA);

        // Relawan memulai lewat Mini App lalu membatalkan: jumlah perjalanan AKTIF kembali 0, status jadwal tetap siap.
        $this->as($kurir);
        $id = $this->postJson('/api/v1/notifikasi-perjalanan', ['jadwal_distribusi_id' => $jadwal->id])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/notifikasi-perjalanan/{$id}/status", ['status' => 'dibatalkan', 'alasan' => 'Ban bocor'])->assertOk();
        $this->forgetActor();
        $this->flushHeaders();
        Event::assertDispatchedTimes(PerjalananDimulai::class, 1);

        $this->tekan($this->kepalaA, $ya);

        $this->assertSame(BotAksiTulisService::PESAN_BERUBAH, $this->teksLayar($this->kepalaA));
        $this->assertSame(1, $this->jumlahPerjalanan($jadwal->id), 'Tidak ada perjalanan kedua');
        Event::assertDispatchedTimes(PerjalananDimulai::class, 1);
    }

    public function test_relawan_distribusi_lintas_sppg_dan_jadwal_bukan_hari_ini_ditolak(): void
    {
        $jadwalB = $this->jadwalSiap($this->sppgB);
        $besok = $this->jadwalSiap($this->sppgA, tanggal: now(config('operasional.timezone'))->addDay()->toDateString());
        $kurir = $this->relawanDistribusi();
        $this->sesiPenuh($kurir);

        foreach ([BotAksi::JENIS_PICU_PERJALANAN, BotAksi::JENIS_YA_PICU_PERJALANAN] as $jenis) {
            $this->tekan($kurir, $this->token($kurir, $jenis, $this->opsi($this->sppgA, BotAksi::SUBJEK_JADWAL_DISTRIBUSI, $jadwalB->id, $this->versiAsli(BotAksi::JENIS_PICU_PERJALANAN, $jadwalB))));
            $this->assertSame(BotCallbackHandler::TIDAK_BERHAK, $this->jawabanTerakhir(), "{$jenis} lintas SPPG");

            $this->tekan($kurir, $this->token($kurir, $jenis, $this->opsi($this->sppgA, BotAksi::SUBJEK_JADWAL_DISTRIBUSI, $besok->id, $this->versiAsli(BotAksi::JENIS_PICU_PERJALANAN, $besok))));
            $this->assertSame('Aksi tidak lagi tersedia.', $this->jawabanTerakhir(), "{$jenis} bukan hari ini");
            $this->assertStringContainsString('hanya dapat dimulai untuk jadwal distribusi hari ini', $this->teksLayar($kurir));
        }

        $this->assertSame(0, $this->jumlahPerjalanan($jadwalB->id) + $this->jumlahPerjalanan($besok->id));
        Event::assertNotDispatched(PerjalananDimulai::class);
    }

    public function test_layar_mulai_perjalanan_dijeda_seperti_layar_berat(): void
    {
        $this->jadwalSiap($this->sppgA);
        config(['telegram.layar_berat_jeda_detik' => 3]);
        $this->sesiPenuh($this->kepalaA);

        $this->assertNotNull($this->buka($this->kepalaA, 'mp'));
        $this->assertNull($this->buka($this->kepalaA, 'mp'));
        $this->assertSame('Terlalu cepat. Coba lagi sebentar.', $this->jawabanTerakhir());
        $this->travel(4)->seconds();
        $this->assertNotNull($this->buka($this->kepalaA, 'mp'));
    }

    /** E2-4: galat SETELAH commit (menyusun layar hasil / mengedit pesan) tidak dilaporkan "gagal" dan tidak menyuruh coba lagi buta. */
    public function test_galat_setelah_commit_dilaporkan_sudah_diproses(): void
    {
        $this->sesiPenuh($this->kepalaA);

        foreach (['layar', 'edit'] as $titik) {
            $k = $this->keluhan($this->sppgA);
            $this->app->forgetInstance(TelegramBotService::class);
            $this->keKonfirmasi($this->kepalaA, 'kl', 'Proses keluhan #'.$k->id);
            $ya = $this->ya($this->kepalaA);
            $this->logBot = [];

            $this->app->instance(TelegramBotService::class, $titik === 'layar'
                ? new class extends TelegramBotService
                {
                    public function miniAppUrl(array $query = [], ?string $hashPath = null): string
                    {
                        throw new \RuntimeException('render gagal');
                    }
                }
                : new class extends TelegramBotService
                {
                    public function editMessageText(int|string $chatId, int $messageId, string $text, array $options = []): array|bool
                    {
                        throw new \RuntimeException('edit gagal');
                    }
                });
            $this->tekan($this->kepalaA, $ya);

            $this->assertSame('diproses', $this->keluhanRaw($k->id)->status->value, $titik);
            $teks = $titik === 'layar' ? $this->teksLayar($this->kepalaA) : (string) $this->messagesTo((int) $this->kepalaA->telegram_id)->last()['text'];
            $this->assertSame(BotAksiTulisService::PESAN_SUDAH_DIPROSES, $teks, $titik);
            $this->assertSame('diproses', BotAksi::query()->where('kode', explode('.', $ya)[1])->value('hasil'), "{$titik}: hasil sesuai efek, bukan gagal");
            $this->assertTrue(collect($this->logBot)->contains(fn ($l) => $l['message'] === 'bot.galat'), $titik);
            $this->assertFalse(collect($this->logBot)->contains(fn ($l) => $l['message'] === 'bot.aksi' && ($l['context']['hasil'] ?? null) === 'gagal'), $titik);
        }

        $this->app->forgetInstance(TelegramBotService::class);
    }

    // ------------------------------------------------------------------ tindak lanjut keluhan

    public function test_kepala_proses_keluhan_baru_tanpa_teks_keluhan_di_chat(): void
    {
        $k = $this->keluhan($this->sppgA);
        $this->keluhan($this->sppgA, 'diproses');
        $this->sesiPenuh($this->kepalaA);

        $this->buka($this->kepalaA, 'kl');
        $label = array_column($this->tombolLayar((int) $this->kepalaA->telegram_id), 'text');
        $this->assertSame(['Proses keluhan #'.$k->id], array_values(array_filter($label, fn ($t) => str_starts_with($t, 'Proses keluhan'))), 'Hanya keluhan berstatus baru');

        $this->keKonfirmasi($this->kepalaA, 'kl', 'Proses keluhan #'.$k->id);
        $teks = $this->teksLayar($this->kepalaA);
        $this->assertStringContainsString('Status: Baru -&gt; Diproses', $teks);
        $this->assertStringContainsString('Isi keluhan tidak ditampilkan', $teks);
        $this->assertTrue($this->adaTombol($this->kepalaA, 'Buka di Mini App'));
        $this->assertFalse($this->adaTombol($this->kepalaA, 'Selesai'));

        $this->tekan($this->kepalaA, $this->ya($this->kepalaA));

        $segar = $this->keluhanRaw($k->id);
        $this->assertSame('diproses', $segar->status->value);
        $this->assertSame((int) $this->kepalaA->id, (int) $segar->ditangani_oleh);
        $this->assertNull($segar->tindak_lanjut);
        $this->assertStringContainsString('kini Diproses', $this->teksLayar($this->kepalaA));

        $audit = AuditLog::query()->withoutGlobalScopes()->where('event', 'bot_tindak_keluhan')->sole();
        $this->assertSame(['status' => 'baru'], $audit->old_values);
        $this->assertSame(['status' => 'diproses'], $audit->new_values);
        $this->assertSame('bot', $audit->sumber);
        $this->assertTanpaBocor();
    }

    public function test_batal_tidak_mengubah_data_dan_mematikan_tombol_ya(): void
    {
        $k = $this->keluhan($this->sppgA);
        $this->sesiPenuh($this->kepalaA);
        $this->keKonfirmasi($this->kepalaA, 'kl', 'Proses keluhan');
        $ya = $this->ya($this->kepalaA);

        $this->tekan($this->kepalaA, $this->batal($this->kepalaA));
        $this->assertStringContainsString('Dibatalkan. Tidak ada perubahan data.', $this->teksLayar($this->kepalaA));
        $this->assertSame(BotAksi::STATUS_KEDALUWARSA, $this->statusToken($ya));

        $this->tekan($this->kepalaA, $ya);
        $this->assertSame(BotCallbackHandler::TIDAK_BERLAKU, $this->jawabanTerakhir());
        $this->assertSame('baru', $this->keluhanRaw($k->id)->status->value);
    }

    public function test_layar_aksi_terlindungi_saat_fallback_kirim(): void
    {
        $this->keluhan($this->sppgA);
        $this->sesiPenuh($this->kepalaA);
        $this->buka($this->kepalaA, 'kl');
        $aksi = $this->dataTombol($this->kepalaA, 'Proses keluhan');

        $this->fakeTelegram(['api.telegram.org/*/editMessageText' => Http::response(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: message to edit not found'], 400)]);
        $this->tekan($this->kepalaA, $aksi);

        $pesan = $this->messagesTo((int) $this->kepalaA->telegram_id)->last();
        $this->assertStringContainsString('Konfirmasi:', $pesan['text']);
        $this->assertSame('true', $pesan['protect_content']);
    }

    public function test_relawan_dapur_tidak_punya_layar_mulai_perjalanan(): void
    {
        $this->jadwalSiap($this->sppgA);
        $this->sesiPenuh($this->relawanA);
        $this->assertSame(RelawanUnit::Dapur, $this->relawanA->unit);
        $this->assertSame(Role::Relawan, $this->relawanA->role);

        $this->assertNull($this->buka($this->relawanA, 'mp'));
        $this->assertSame(BotCallbackHandler::TIDAK_BERHAK_LIHAT, $this->jawabanTerakhir());
    }
}
