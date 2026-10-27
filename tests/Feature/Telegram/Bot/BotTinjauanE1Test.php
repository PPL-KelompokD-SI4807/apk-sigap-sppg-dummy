<?php

namespace Tests\Feature\Telegram\Bot;

use App\Models\BotAksi;
use App\Services\Auth\SesiService;
use App\Services\Telegram\Bot\BotCallbackHandler;
use App\Services\Telegram\Bot\BotLayarRegistry;
use App\Services\Telegram\Bot\BotNavCodec;
use App\Services\Telegram\Bot\BotNavigasi;
use App\Services\Telegram\Bot\Layar\KonteksLayar;
use App\Services\Telegram\TelegramUpdateHandler;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use LogicException;

/**
 * Perbaikan temuan tinjauan opus E1 PR-2: token keputusan investor tidak menumpuk, edit netral saat klik ditolak,
 * layar data b1/n1 selalu terlindungi (termasuk fallback sendMessage), admin baca-saja tanpa efek samping non-DB,
 * dan batas panjang id layar.
 */
class BotTinjauanE1Test extends LayarBacaTestCase
{
    private function editGagal(): array
    {
        return ['api.telegram.org/*/editMessageText' => Http::response(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: message to edit not found'], 400)];
    }

    private function jumlahDaftar(): int
    {
        return BotAksi::query()->where('jenis', BotAksi::JENIS_DAFTAR)->count();
    }

    // ------------------------------------------------------------------ 1. bom token beranda investor

    public function test_render_dan_segarkan_beranda_investor_tidak_menumpuk_baris_bot_aksi(): void
    {
        $this->sesiPenuh($this->investorC, $this->investorAB);
        $this->ketik($this->investorC, '/menu');
        $this->assertSame(1, $this->jumlahDaftar());

        for ($i = 0; $i < 30; $i++) {
            $this->tekan($this->investorC, $this->dataTombol($this->investorC, 'Segarkan'));
        }

        $this->assertSame(1, $this->jumlahDaftar(), '30 kali Segarkan memakai ulang token yang sama');
        $this->assertSame(1, BotAksi::query()->where('user_id', $this->investorC->id)->count());

        // Investor banyak SPPG: satu token per SPPG terpilih, bukan per render.
        $this->ketik($this->investorAB, '/menu');
        $this->tekan($this->investorAB, $this->dataTombol($this->investorAB, 'Pilih SPPG'));
        $this->tekan($this->investorAB, $this->dataTombol($this->investorAB, 'SPPG A'));

        for ($i = 0; $i < 30; $i++) {
            $this->tekan($this->investorAB, $this->dataTombol($this->investorAB, 'Segarkan'));
        }

        $this->assertSame(2, BotAksi::query()->where('user_id', $this->investorAB->id)->count(), 'Satu token per konteks SPPG (belum dipilih, SPPG A), bukan per render');
    }

    public function test_token_keputusan_dipakai_ulang_tetap_sekali_pakai_dan_diterbitkan_baru_setelah_dipakai(): void
    {
        $this->pengajuanMenu(); // SPPG A; investor AB. SPPG C kosong tetap boleh membuka daftar.
        $this->sesiPenuh($this->investorC);
        $this->ketik($this->investorC, '/menu');
        $token = $this->dataTombol($this->investorC, 'Menunggu keputusan');
        $this->tekan($this->investorC, $this->dataTombol($this->investorC, 'Segarkan'));
        $this->assertSame($token, $this->dataTombol($this->investorC, 'Menunggu keputusan'), 'Token aktif dipakai ulang');

        $this->tekan($this->investorC, $token);
        $this->assertSame(1, BotAksi::query()->where('status', BotAksi::STATUS_SELESAI)->count());
        $sebelum = $this->telegramCalls('answerCallbackQuery')->count();
        $this->tekan($this->investorC, $token);
        $this->assertSame(BotCallbackHandler::TIDAK_BERLAKU, $this->jawabanTerakhir(), 'Sekali pakai');
        $this->assertGreaterThan($sebelum, $this->telegramCalls('answerCallbackQuery')->count());

        // Beranda berikutnya menerbitkan token baru karena yang lama sudah terpakai.
        $this->ketik($this->investorC, '/menu');
        $this->assertNotSame($token, $this->dataTombol($this->investorC, 'Menunggu keputusan'));
    }

    // ------------------------------------------------------------------ 2. edit netral

    public function test_klik_ditolak_hak_mengedit_pesan_asal_menjadi_netral_tanpa_data_dan_tombol(): void
    {
        $this->isiData($this->sppgA, 'Alfa');
        $this->sesiPenuh($this->akuntanA);
        $this->assertStringContainsString('Rp 123.000', (string) $this->buka($this->akuntanA, 'pk'));
        $token = $this->n1($this->akuntanA, 'pk', $this->sppgA);

        DB::table('users')->where('id', $this->akuntanA->id)->update(['role' => 'staf_gudang']);
        $this->tekan($this->akuntanA, $token);

        $edit = $this->telegramCalls('editMessageText')->last();
        $this->assertSame(BotCallbackHandler::LAYAR_DITUTUP_HAK, $edit['text']);
        $this->assertSame([], $this->markupOf($edit)['inline_keyboard'] ?? []);
        $this->assertStringNotContainsString('Rp', (string) $edit['text']);
        $this->assertSame(BotCallbackHandler::TIDAK_BERHAK_LIHAT, $this->jawabanTerakhir());
        $this->assertSame(1, $this->jumlahEditNetral());
    }

    public function test_sesi_dicabut_mengedit_pesan_asal_netral_dan_tetap_kirim_tombol_masuk(): void
    {
        $this->sesiPenuh($this->kepalaA);
        $token = $this->n1($this->kepalaA, 'st', $this->sppgA);
        app(SesiService::class)->keluarSemua($this->kepalaA);
        $this->fakeTelegram();

        $this->tekan($this->kepalaA, $token);

        $this->assertSame(BotCallbackHandler::LAYAR_DITUTUP_SESI, $this->telegramCalls('editMessageText')->last()['text']);
        $this->assertSame(TelegramUpdateHandler::PERLU_MASUK, $this->messagesTo((int) $this->kepalaA->telegram_id)->last()['text']);
    }

    public function test_edit_netral_gagal_tetap_aman_tanpa_pesan_data_baru(): void
    {
        $this->isiData($this->sppgA, 'Alfa');
        $this->sesiPenuh($this->akuntanA);
        $token = $this->n1($this->akuntanA, 'pk', $this->sppgA);
        DB::table('users')->where('id', $this->akuntanA->id)->update(['role' => 'staf_gudang']);
        $this->fakeTelegram($this->editGagal());

        $this->tekan($this->akuntanA, $token);

        $this->assertSame(BotCallbackHandler::TIDAK_BERHAK_LIHAT, $this->jawabanTerakhir());
        $this->assertCount(0, $this->messagesTo((int) $this->akuntanA->telegram_id), 'Tidak ada pesan baru berisi data');
    }

    // ------------------------------------------------------------------ 3 dan 5. protect_content

    public function test_pesan_akar_dan_fallback_edit_semua_layar_n1_terlindungi(): void
    {
        $this->isiData($this->sppgA, 'Alfa');
        $this->sesiPenuh($this->kepalaA);
        $uid = (int) $this->kepalaA->telegram_id;

        foreach (['/menu', '/start', '/bantuan', ...array_map(fn ($p) => '/'.$p, array_keys(BotLayarRegistry::PERINTAH))] as $perintah) {
            $this->ketik($this->kepalaA, $perintah);
            $this->assertSame('true', $this->messagesTo($uid)->last()['protect_content'] ?? null, "{$perintah} wajib protect_content=true");
        }

        // Fallback bila edit gagal: sendMessage tetap terlindungi untuk setiap layar data.
        $this->fakeTelegram($this->editGagal());

        foreach ($this->idLayarData() as $id) {
            $sebelum = $this->messagesTo($uid)->count();
            $this->tekan($this->kepalaA, $this->n1($this->kepalaA, $id, $this->sppgA));
            $pesan = $this->messagesTo($uid);

            if ($pesan->count() > $sebelum) {
                $this->assertSame('true', $pesan->last()['protect_content'] ?? null, "fallback {$id}");
            }
        }

        $this->assertGreaterThan(10, $this->messagesTo($uid)->count());
    }

    public function test_layar_keputusan_b1_terlindungi_termasuk_fallback_edit(): void
    {
        $this->pengajuanMenu();
        $this->pengeluaranMenunggu();
        $this->sesiPenuh($this->investorC, $this->investorAB);

        // Investor banyak SPPG: layar pemilih -> daftar -> tinjau, semuanya lewat fallback sendMessage.
        $this->ketik($this->investorAB, '/menu');
        $daftar = $this->dataTombol($this->investorAB, 'Menunggu keputusan');
        $this->fakeTelegram($this->editGagal());
        $this->tekan($this->investorAB, $daftar);
        $this->assertSame('true', $this->messagesTo((int) $this->investorAB->telegram_id)->last()['protect_content'] ?? null, 'pemilih SPPG');

        $this->tekan($this->investorAB, $this->dataTombol($this->investorAB, 'SPPG A'));
        $this->assertSame('true', $this->messagesTo((int) $this->investorAB->telegram_id)->last()['protect_content'] ?? null, 'daftar');

        $this->tekan($this->investorAB, $this->dataTombol($this->investorAB, 'Tinjau menu'));
        $tinjau = $this->messagesTo((int) $this->investorAB->telegram_id)->last();
        $this->assertSame('true', $tinjau['protect_content'] ?? null, 'tinjau');
        $this->assertStringContainsString('Setujui', json_encode($this->markupOf($tinjau)));

        $this->tekan($this->investorAB, $this->dataTombol($this->investorAB, 'Setujui'));
        $this->assertSame('true', $this->messagesTo((int) $this->investorAB->telegram_id)->last()['protect_content'] ?? null, 'hasil keputusan');
    }

    // ------------------------------------------------------------------ 4. admin baca-saja tanpa efek samping

    public function test_admin_baca_saja_tidak_memicu_antrean_event_notifikasi_dan_tidak_menulis_cache_ringkasan(): void
    {
        $this->isiData($this->sppgA, 'Alfa');
        $this->sesiPenuh($this->admin, $this->kepalaA);
        config(['telegram.ringkasan_cache_detik' => 60]);
        Cache::flush();

        $events = array_map(
            fn (string $f) => 'App\\Events\\'.basename($f, '.php'),
            glob(app_path('Events/*.php')) ?: [],
        );
        $this->assertNotEmpty($events);
        Queue::fake();
        Bus::fake();
        Notification::fake();
        Mail::fake();
        Event::fake($events);

        $kunci = fn (string $profil) => 'bot:ringkasan:v1:'.$this->sppgA.':'.now(config('operasional.timezone', 'Asia/Jakarta'))->toDateString().':'.$profil;

        // Langsung lewat BotNavigasi (webhook sendiri memakai antrean, yang di-fake di sini).
        $nav = app(BotNavigasi::class);
        $konteks = new KonteksLayar((int) $this->admin->telegram_id, (int) $this->admin->telegram_id);
        $beranda = $nav->buka($this->admin, BotLayarRegistry::BERANDA, $this->sppgA, $konteks);
        $this->assertTrue($beranda->berhasil());
        $this->assertStringContainsString('Ringkasan hari ini', $beranda->layar->teks);

        foreach ($this->idLayarData() as $id) {
            $this->assertTrue($nav->buka($this->admin, $id, $this->sppgA, $konteks)->berhasil(), "admin/{$id}");
        }

        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
        Notification::assertNothingSent();
        Mail::assertNothingSent();
        Event::assertNothingDispatched();
        $this->assertFalse(Cache::has($kunci('admin')), 'Cache ringkasan tidak ditulis dari transaksi baca-saja admin');
        $this->assertSame(0, BotAksi::query()->count());

        // Peran non-admin tetap memakai cache ringkasan.
        $this->assertTrue($nav->buka($this->kepalaA, BotLayarRegistry::BERANDA, $this->sppgA, new KonteksLayar((int) $this->kepalaA->telegram_id, (int) $this->kepalaA->telegram_id))->berhasil());
        $this->assertTrue(Cache::has($kunci('kepala_sppg')));
    }

    // ------------------------------------------------------------------ 12. panjang id layar

    public function test_id_layar_maks_tujuh_karakter_dan_tidak_berakhiran_angka(): void
    {
        new BotLayarRegistry([...BotLayarRegistry::layarBawaan(), new LayarUji('abcdefg', 'mutu')]);
        $this->addToAssertionCount(1);

        foreach (['abcdefgh', 'ab2', 'a1'] as $id) {
            try {
                new BotLayarRegistry([...BotLayarRegistry::layarBawaan(), new LayarUji($id, 'mutu')]);
                $this->fail("id {$id} harus ditolak");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        // Id halaman layar terpanjang (7 + 1 angka) tetap muat sebagai token navigasi.
        $this->assertSame(['abcdefg', 3], BotLayarRegistry::pecahId(BotLayarRegistry::idHalaman('abcdefg', 3)));
        $this->assertLessThan(64, strlen(BotNavCodec::data(BotLayarRegistry::idHalaman('abcdefg', 3), 9999999, 123456789012)));
    }
}
