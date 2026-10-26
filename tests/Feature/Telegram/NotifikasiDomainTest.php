<?php

namespace Tests\Feature\Telegram;

use App\Enums\Role;
use App\Enums\StatusKirim;
use App\Events\AnggaranMelampaui;
use App\Events\AnggaranMencapai80;
use App\Events\BuktiDistribusiDicatat;
use App\Events\DistribusiTerlambat;
use App\Events\KeluhanKritis;
use App\Events\MutuTidakLayak;
use App\Events\PerjalananDimulai;
use App\Events\PerjalananTerlambat;
use App\Events\RekapHarianDibuat;
use App\Events\StokMenipis;
use App\Jobs\SendTelegramNotification;
use App\Listeners\KirimNotifikasiDomain;
use App\Models\NotifikasiPerjalanan;
use App\Models\RekapHarian;
use App\Models\TitikLayanan;
use App\Models\User;
use App\Services\Notification\DomainEventNotifications;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Master\MasterApiTestCase;
use Tests\Feature\Telegram\Events\BuktiDistribusiBelumMasuk;

class NotifikasiDomainTest extends MasterApiTestCase
{
    use FakesTelegram;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeTelegram();
        $this->forgetActor();
    }

    /** @return list<int> chat id penerima sendMessage */
    private function recipients(): array
    {
        return $this->telegramCalls('sendMessage')->map(fn (Request $r) => (int) $r['chat_id'])->sort()->values()->all();
    }

    /** @param list<User> $users @return list<int> */
    private function chats(array $users): array
    {
        return collect($users)->map(fn (User $u) => (int) $u->telegram_id)->sort()->values()->all();
    }

    private function stok(int $sppgId, string $nama = 'Beras'): StokMenipis
    {
        return new StokMenipis($sppgId, 11, $nama, 'kg', '4.000', '10.000');
    }

    public function test_listener_terdaftar_untuk_semua_event_domain_yang_ada(): void
    {
        $registered = DomainEventNotifications::registeredEventClasses();

        foreach (DomainEventNotifications::EVENTS as $name) {
            $class = 'App\\Events\\'.$name;
            $this->assertSame(class_exists($class), in_array($class, $registered, true), $name);

            if (class_exists($class)) {
                $this->assertTrue(Event::hasListeners($class), "Listener belum terdaftar untuk {$name}");
            }
        }

        $this->assertContains(StokMenipis::class, $registered);
    }

    public function test_stok_menipis_ke_staf_gudang_dan_kepala_aktif_ber_telegram_di_sppg_sama(): void
    {
        User::factory()->role(Role::StafGudang)->inactive()->create(['sppg_id' => $this->sppgA]);
        User::factory()->role(Role::StafGudang)->withoutTelegram()->create(['sppg_id' => $this->sppgA]);
        $terhapus = User::factory()->role(Role::StafGudang)->create(['sppg_id' => $this->sppgA]);
        $terhapus->delete();
        User::factory()->role(Role::StafGudang)->create(['sppg_id' => $this->sppgB]);

        event($this->stok($this->sppgA, 'Beras <script>alert(1)</script>'));

        $this->assertSame($this->chats([$this->stafGudangA, $this->kepalaA]), $this->recipients());
        $sent = $this->telegramCalls('sendMessage')->first();
        $this->assertSame('HTML', $sent['parse_mode']);
        $this->assertStringContainsString('Beras &lt;script&gt;alert(1)&lt;/script&gt;', $sent['text']);
        $this->assertStringNotContainsString('<script>', $sent['text']);
        $this->assertStringContainsString('sisa 4 kg (minimum 10 kg)', $sent['text']);
        $this->assertSame('https://sigap.test/miniapp/#/bahan-baku', $this->markupOf($sent)['inline_keyboard'][0][0]['web_app']['url']);
    }

    public function test_event_ganda_tidak_menggandakan_pesan(): void
    {
        event($this->stok($this->sppgA));
        event($this->stok($this->sppgA));

        $this->assertCount(2, $this->telegramCalls('sendMessage'));
    }

    public function test_mutu_tidak_layak_ke_kepala_dan_ahli_gizi(): void
    {
        event(new MutuTidakLayak($this->sppgA, 5, 7, '2026-11-02', 'Nasi Ayam Suwir', $this->ahliGiziA->id, 'Masak ulang', 2));

        $this->assertSame($this->chats([$this->kepalaA, $this->ahliGiziA]), $this->recipients());
        $text = $this->telegramCalls('sendMessage')->first()['text'];
        $this->assertStringContainsString('TIDAK LAYAK', $text);
        $this->assertStringContainsString('2 jadwal distribusi ditahan', $text);
    }

    public function test_keluhan_kritis_ke_kepala_dan_ahli_gizi_atau_admin_bila_tidak_ada_penerima(): void
    {
        event(new KeluhanKritis(1, $this->sppgA, 'makanan_basi', 'kritis', null, 'SDN 1 Fiktif', null, 'Nasi berbau basi', now()->toIso8601String()));
        $this->assertSame($this->chats([$this->kepalaA, $this->ahliGiziA]), $this->recipients());
        $this->assertStringContainsString('Makanan Basi', $this->telegramCalls('sendMessage')->first()['text']);

        $this->fakeTelegram();
        $sppgKosong = $this->createSppg('SPPG Kosong');
        event(new KeluhanKritis(2, $sppgKosong, 'gejala_sakit', 'kritis', null, null, null, 'Siswa mual', now()->toIso8601String()));
        $this->assertSame($this->chats([$this->admin]), $this->recipients());
    }

    public function test_perjalanan_dimulai_ke_pic_tanpa_tombol_dan_status_terkirim(): void
    {
        $titik = TitikLayanan::factory()->create(['sppg_id' => $this->sppgA, 'pic_telegram_chat_id' => 880001]);
        $n = NotifikasiPerjalanan::factory()->create([
            'sppg_id' => $this->sppgA, 'titik_layanan_id' => $titik->id, 'status_kirim' => StatusKirim::Menunggu,
            'waktu_kirim' => null, 'penerima_chat_id' => 880001, 'pesan' => 'Mobil B 1234 XY berangkat menuju <SDN>.',
        ]);

        event(PerjalananDimulai::dari($n));

        $this->assertSame([880001], $this->recipients());
        $sent = $this->telegramCalls('sendMessage')->sole();
        $this->assertNull($this->markupOf($sent));
        $this->assertStringContainsString('menuju &lt;SDN&gt;', $sent['text']);
        $this->assertSame(StatusKirim::Terkirim, $n->fresh()->status_kirim);
        $this->assertNotNull($n->fresh()->waktu_kirim);
    }

    public function test_perjalanan_tanpa_pic_dialihkan_ke_kepala_dan_status_tetap_tidak_terkirim(): void
    {
        $titik = TitikLayanan::factory()->create(['sppg_id' => $this->sppgA, 'pic_telegram_chat_id' => null]);
        $n = NotifikasiPerjalanan::factory()->tidakTerkirim()->create(['sppg_id' => $this->sppgA, 'titik_layanan_id' => $titik->id]);

        event(PerjalananDimulai::dari($n));

        $this->assertSame($this->chats([$this->kepalaA]), $this->recipients());
        $this->assertStringContainsString('dialihkan ke Anda', $this->telegramCalls('sendMessage')->sole()['text']);
        $this->assertSame(StatusKirim::TidakTerkirim, $n->fresh()->status_kirim);
    }

    public function test_pic_memblokir_bot_status_gagal(): void
    {
        $this->fakeTelegram(['api.telegram.org/*/sendMessage' => Http::response(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'], 403)]);
        $titik = TitikLayanan::factory()->create(['sppg_id' => $this->sppgA, 'pic_telegram_chat_id' => 880002]);
        $n = NotifikasiPerjalanan::factory()->create(['sppg_id' => $this->sppgA, 'titik_layanan_id' => $titik->id, 'status_kirim' => StatusKirim::Menunggu]);

        event(PerjalananDimulai::dari($n));

        $this->assertSame(StatusKirim::Gagal, $n->fresh()->status_kirim);
        $this->assertStringContainsString('403', (string) $n->fresh()->error);
    }

    public function test_galat_sementara_dicatat_gagal_tanpa_menggagalkan_alur_domain(): void
    {
        $this->fakeTelegram(['api.telegram.org/*/sendMessage' => Http::response(['ok' => false, 'error_code' => 502, 'description' => 'Bad Gateway'], 502)]);
        $titik = TitikLayanan::factory()->create(['sppg_id' => $this->sppgA, 'pic_telegram_chat_id' => 880003]);
        $n = NotifikasiPerjalanan::factory()->create(['sppg_id' => $this->sppgA, 'titik_layanan_id' => $titik->id, 'status_kirim' => StatusKirim::Menunggu]);

        event(PerjalananDimulai::dari($n));

        $this->assertSame(StatusKirim::Gagal, $n->fresh()->status_kirim);
    }

    public function test_perjalanan_terlambat_ke_pic_dan_kepala(): void
    {
        $titik = TitikLayanan::factory()->create(['sppg_id' => $this->sppgA, 'pic_telegram_chat_id' => 880004]);
        $n = NotifikasiPerjalanan::factory()->create(['sppg_id' => $this->sppgA, 'titik_layanan_id' => $titik->id]);

        event(PerjalananTerlambat::dari($n));

        $this->assertSame(collect([880004, (int) $this->kepalaA->telegram_id])->sort()->values()->all(), $this->recipients());
    }

    public function test_rekap_harian_ke_kepala_ahli_gizi_akuntan_dan_status_terkirim(): void
    {
        $rekap = RekapHarian::factory()->create(['sppg_id' => $this->sppgA, 'status_kirim' => StatusKirim::Menunggu, 'dikirim_pada' => null, 'ringkasan' => [
            'porsi' => ['target' => 1000, 'realisasi' => 950, 'capaian_persen' => 95.0],
            'keuangan' => ['serapan_persen' => 62.5, 'sisa_anggaran' => '43750000.00'],
        ]]);

        event(RekapHarianDibuat::dari($rekap));

        $this->assertSame($this->chats([$this->kepalaA, $this->ahliGiziA, $this->akuntanA]), $this->recipients());
        $text = $this->telegramCalls('sendMessage')->first()['text'];
        $this->assertStringContainsString('Porsi: 950/1.000 (95%)', $text);
        $this->assertStringContainsString('sisa Rp 43.750.000', $text);
        $this->assertSame(StatusKirim::Terkirim, $rekap->fresh()->status_kirim);
        $this->assertNotNull($rekap->fresh()->dikirim_pada);
    }

    public function test_rekap_tanpa_penerima_tidak_terkirim(): void
    {
        $sppg = $this->createSppg('SPPG Sepi');
        $rekap = RekapHarian::factory()->create(['sppg_id' => $sppg, 'status_kirim' => StatusKirim::Menunggu]);

        event(RekapHarianDibuat::dari($rekap));

        Http::assertNothingSent();
        $this->assertSame(StatusKirim::TidakTerkirim, $rekap->fresh()->status_kirim);
    }

    public function test_anggaran_ke_akuntan_dan_kepala_memakai_payload_nyata(): void
    {
        event(new AnggaranMencapai80($this->sppgA, '2026-11', 50000000, 41000000, 82.0));

        $this->assertSame($this->chats([$this->akuntanA, $this->kepalaA]), $this->recipients());
        $text = $this->telegramCalls('sendMessage')->first()['text'];
        $this->assertStringContainsString('mencapai 80%', $text);
        $this->assertStringContainsString('serapan 82%', $text);
        $this->assertStringContainsString('sisa Rp 9.000.000', $text);

        $this->fakeTelegram();
        event(new AnggaranMelampaui($this->sppgA, '2026-11', 50000000, 52500000, 105.0));
        $this->assertStringContainsString('kelebihan Rp 2.500.000', $this->telegramCalls('sendMessage')->first()['text']);
    }

    public function test_bukti_distribusi_dan_distribusi_terlambat_ke_kepala(): void
    {
        $args = [9, $this->sppgA, 3, 4, 'SDN 4 Fiktif', 240, 238, 'Bu Ani', '2026-11-02T10:45:00+07:00', '2026-11-02T10:00:00+07:00', true, 45, $this->relawanA->id];

        event(new BuktiDistribusiDicatat(...$args));
        event(new DistribusiTerlambat(...$args));

        $this->assertSame($this->chats([$this->kepalaA, $this->kepalaA]), $this->recipients());
        [$dicatat, $terlambat] = $this->telegramCalls('sendMessage')->pluck('text')->all();
        $this->assertStringContainsString('238 porsi diterima dari 240 dikirim', $dicatat);
        $this->assertStringContainsString('tiba 10:45 WIB, terlambat 45 menit dari acuan 10:00 WIB', $terlambat);
        $this->assertStringNotContainsString('belum memiliki bukti', $terlambat);
    }

    public function test_event_yang_belum_ada_dipetakan_dari_nama_dan_payload_yang_diharapkan(): void
    {
        app(KirimNotifikasiDomain::class)->handle(new BuktiDistribusiBelumMasuk($this->sppgA, 3, 4, 'SDN 4 Fiktif', '10:00:00', 60));

        $this->assertSame($this->chats([$this->kepalaA]), $this->recipients());
        $text = $this->telegramCalls('sendMessage')->sole()['text'];
        $this->assertStringContainsString('belum memiliki bukti serah terima (rencana tiba 10:00 WIB), lewat 60 menit', $text);
    }

    public function test_penerima_dicek_ulang_saat_job_berjalan(): void
    {
        $job = new SendTelegramNotification((int) $this->kepalaA->telegram_id, '<b>x</b>', null, $this->kepalaA->id, 'uji');
        $this->kepalaA->forceFill(['is_active' => false])->save();

        app()->call([$job, 'handle']);

        Http::assertNothingSent();
    }

    public function test_bot_belum_dikonfigurasi_tidak_mengirim(): void
    {
        config(['telegram.bot_token' => null]);

        event($this->stok($this->sppgA));

        Http::assertNothingSent();
    }

    public function test_escape_html_dan_markdown_v2(): void
    {
        $this->assertSame('&lt;b&gt;A &amp; B&lt;/b&gt; &quot;x&quot;', TelegramBotService::escapeHtml('<b>A & B</b> "x"'));
        $this->assertSame('1\\.5 \\*tebal\\* \\[a\\]\\(b\\) \\_x\\_ \\!', TelegramBotService::escapeMarkdownV2('1.5 *tebal* [a](b) _x_ !'));
    }
}
