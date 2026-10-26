<?php

namespace Tests\Feature\Telegram;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Enums\TipeUndangan;
use App\Jobs\ProcessAiMessage;
use App\Jobs\ProcessTelegramUpdate;
use App\Models\Invitation;
use App\Models\TelegramUpdate;
use App\Models\TitikLayanan;
use App\Models\User;
use App\Services\Ai\Llm\FakeLlmClient;
use App\Services\Ai\Llm\LlmClient;
use App\Services\Ai\Llm\LlmResponse;
use App\Services\Auth\KredensialService;
use App\Services\Auth\SesiService;
use App\Services\Telegram\AiChatHandler;
use App\Services\Telegram\TelegramUpdateHandler;
use App\Services\Users\InvitationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Master\MasterApiTestCase;

class TelegramWebhookTest extends MasterApiTestCase
{
    use FakesTelegram;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeTelegram();
        // Agen AI di bot hanya untuk pengguna dengan sesi login penuh (R-03).
        $this->beriSesiPenuh($this->kepalaA);
    }

    private function invite(TipeUndangan $tipe, array $attrs): string
    {
        return app(InvitationService::class)->issue($this->admin, $tipe, $attrs)->token;
    }

    // ------------------------------------------------------------------ keamanan webhook

    public function test_secret_salah_atau_kosong_ditolak_403_tanpa_memproses(): void
    {
        $update = $this->privateUpdate(555001, ['text' => '/start']);

        $this->webhook($update, null)->assertForbidden()->assertJsonPath('message', 'Akses ditolak.');
        $this->webhook($update, 'salah')->assertForbidden();

        config(['telegram.webhook_secret' => '']);
        $this->webhook($update, '')->assertForbidden();

        config(['telegram.webhook_secret' => null]);
        $this->webhook($update, '')->assertForbidden();

        $this->assertSame(0, TelegramUpdate::query()->count());
        Http::assertNothingSent();
    }

    public function test_idempoten_update_id_duplikat_tidak_diproses_ulang(): void
    {
        $update = $this->privateUpdate((int) $this->kepalaA->telegram_id, ['text' => '/start']);

        $this->webhook($update)->assertOk()->assertJson(['ok' => true]);
        $this->webhook($update)->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(1, TelegramUpdate::query()->count());
        $this->assertCount(1, $this->telegramCalls('sendMessage'));
    }

    public function test_update_tersimpan_tanpa_antrean_diproses_ulang_terjadwal_sekali(): void
    {
        // Simulasi dispatch gagal saat webhook: baris tersimpan, job tidak pernah masuk antrean.
        $simpan = fn (int $id, $dibuat) => TelegramUpdate::query()->forceCreate([
            'update_id' => $id,
            'payload' => $this->privateUpdate((int) $this->kepalaA->telegram_id, ['text' => '/start'], updateId: $id),
            'created_at' => $dibuat,
            'updated_at' => $dibuat,
        ]);
        $tertinggal = $simpan(900001, now()->subMinutes(10));
        $masihDiAntrean = $simpan(900002, now()->subMinute());
        $basi = $simpan(900003, now()->subHours(25));

        $this->artisan('sigap:proses-ulang-telegram')->assertSuccessful();
        $this->artisan('sigap:proses-ulang-telegram')->assertSuccessful();

        $this->assertNotNull($tertinggal->fresh()->processed_at);
        $this->assertNull($masihDiAntrean->fresh()->processed_at);
        $this->assertNull($basi->fresh()->processed_at);
        $this->assertCount(1, $this->telegramCalls('sendMessage'));
    }

    public function test_job_update_yang_sedang_dikunci_tidak_diproses_ganda(): void
    {
        $update = TelegramUpdate::query()->forceCreate([
            'update_id' => 900010,
            'payload' => $this->privateUpdate((int) $this->kepalaA->telegram_id, ['text' => '/start'], updateId: 900010),
        ]);

        $kunci = Cache::lock('telegram-update:'.$update->id, 60);
        $this->assertTrue($kunci->get());
        ProcessTelegramUpdate::dispatchSync($update->id);
        $this->assertNull($update->fresh()->processed_at);
        Http::assertNothingSent();

        $kunci->release();
        ProcessTelegramUpdate::dispatchSync($update->id);
        ProcessTelegramUpdate::dispatchSync($update->id);
        $this->assertNotNull($update->fresh()->processed_at);
        $this->assertCount(1, $this->telegramCalls('sendMessage'));
    }

    public function test_payload_bukan_update_sah_diabaikan_200(): void
    {
        $this->webhook(['bukan' => 'update'])->assertOk();
        $this->webhook(['update_id' => 'abc'])->assertOk();
        $this->postJson('/api/v1/telegram/webhook', [], ['X-Telegram-Bot-Api-Secret-Token' => 'test-webhook-secret'])->assertOk();

        $this->assertSame(0, TelegramUpdate::query()->count());
    }

    public function test_galat_bot_api_tidak_bocor_dan_tetap_200(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 500, 'description' => 'Internal'], 500)]);

        $this->webhook($this->privateUpdate((int) $this->kepalaA->telegram_id, ['text' => '/start']))
            ->assertOk()
            ->assertExactJson(['ok' => true]);
    }

    public function test_chat_grup_dan_pengirim_bot_diabaikan(): void
    {
        $this->webhook($this->privateUpdate((int) $this->kepalaA->telegram_id, ['text' => 'halo'], 'group'))->assertOk();

        $bot = $this->privateUpdate((int) $this->kepalaA->telegram_id, ['text' => 'halo']);
        $bot['message']['from']['is_bot'] = true;
        $this->webhook($bot)->assertOk();

        $this->assertCount(0, $this->telegramCalls('sendMessage'));
        $this->assertSame(2, TelegramUpdate::query()->whereNotNull('processed_at')->count());
    }

    // ------------------------------------------------------------------ onboarding

    public function test_start_dengan_undangan_pengguna_membuat_akun_dan_menyapa_dengan_tombol_mini_app(): void
    {
        $token = $this->invite(TipeUndangan::Pengguna, ['role' => 'relawan', 'unit' => 'distribusi', 'sppg_id' => $this->sppgA]);

        $this->webhook($this->privateUpdate(777001, ['text' => '/start inv_'.$token]))->assertOk();

        $user = User::query()->withoutGlobalScopes()->where('telegram_id', 777001)->firstOrFail();
        $this->assertSame(Role::Relawan, $user->role);
        $this->assertSame(RelawanUnit::Distribusi, $user->unit);

        $sent = $this->messagesTo(777001)->sole();
        $this->assertSame('HTML', $sent['parse_mode']);
        $this->assertStringContainsString('Siti Fiktif', $sent['text']);
        $this->assertStringContainsString('Relawan unit Distribusi', $sent['text']);
        $this->assertStringContainsString('SPPG A', $sent['text']);
        $this->assertSame('https://sigap.test/miniapp/', $this->markupOf($sent)['inline_keyboard'][0][0]['web_app']['url']);

        // Payload diringkas setelah diproses (minimisasi data).
        $this->assertSame(['ringkas' => true, 'jenis' => 'start'], TelegramUpdate::query()->sole()->payload);
    }

    public function test_start_undangan_tidak_valid_kedaluwarsa_dan_terpakai_dibalas_ramah(): void
    {
        $this->webhook($this->privateUpdate(777002, ['text' => '/start inv_TokenFiktifTidakAdaSamaSekali123']))->assertOk();
        $this->assertStringContainsString('Tautan undangan tidak valid', $this->messagesTo(777002)->last()['text']);

        $token = $this->invite(TipeUndangan::Pengguna, ['role' => 'akuntan', 'sppg_id' => $this->sppgA]);
        Invitation::query()->update(['expires_at' => now()->subMinute()]);
        $this->webhook($this->privateUpdate(777002, ['text' => '/start inv_'.$token]))->assertOk();
        $this->assertStringContainsString('kedaluwarsa', $this->messagesTo(777002)->last()['text']);

        $token = $this->invite(TipeUndangan::Pengguna, ['role' => 'akuntan', 'sppg_id' => $this->sppgA]);
        $this->webhook($this->privateUpdate(777003, ['text' => '/start inv_'.$token]))->assertOk();
        $this->webhook($this->privateUpdate(777004, ['text' => '/start inv_'.$token]))->assertOk();
        $this->assertStringContainsString('sudah pernah dipakai', $this->messagesTo(777004)->last()['text']);

        $this->assertFalse(User::query()->withoutGlobalScopes()->whereIn('telegram_id', [777002, 777004])->exists());
    }

    public function test_start_undangan_pic_menautkan_chat_ke_titik_layanan(): void
    {
        $this->forgetActor();
        $titik = TitikLayanan::factory()->create(['sppg_id' => $this->sppgA, 'nama' => 'SDN <1> Fiktif']);
        $token = $this->invite(TipeUndangan::PicTitikLayanan, ['titik_layanan_id' => $titik->id]);

        $this->webhook($this->privateUpdate(888001, ['text' => '/start inv_'.$token]))->assertOk();

        $this->assertSame(888001, $titik->fresh()->pic_telegram_chat_id);
        $sent = $this->messagesTo(888001)->sole();
        $this->assertStringContainsString('SDN &lt;1&gt; Fiktif', $sent['text']);
        $this->assertNull($this->markupOf($sent));
        $this->assertFalse(User::query()->withoutGlobalScopes()->where('telegram_id', 888001)->exists());
    }

    public function test_start_tanpa_token_pengguna_terdaftar_mendapat_tombol_mini_app(): void
    {
        $this->webhook($this->privateUpdate((int) $this->ahliGiziA->telegram_id, ['text' => '/start']))->assertOk();

        $sent = $this->messagesTo((int) $this->ahliGiziA->telegram_id)->sole();
        $this->assertStringContainsString('Selamat datang kembali', $sent['text']);
        $this->assertNotNull($this->markupOf($sent)['inline_keyboard'][0][0]['web_app'] ?? null);
    }

    public function test_pengguna_tidak_terdaftar_diarahkan_ke_admin_tanpa_menyimpan_data(): void
    {
        $this->webhook($this->privateUpdate(999001, ['text' => 'Nama saya Budi, NIK 3201xxxx']))->assertOk();

        $sent = $this->messagesTo(999001)->sole();
        $this->assertStringContainsString('belum terdaftar', $sent['text']);
        $this->assertNull($this->markupOf($sent));
        $this->assertFalse(User::query()->withoutGlobalScopes()->where('telegram_id', 999001)->exists());

        $row = TelegramUpdate::query()->sole();
        $this->assertSame(['ringkas' => true, 'jenis' => 'tidak_terdaftar'], $row->payload);
        $this->assertStringNotContainsString('Budi', (string) json_encode($row->getAttributes()));
    }

    public function test_pengguna_nonaktif_tidak_dilayani_agen_ai(): void
    {
        $fake = new FakeLlmClient([LlmResponse::text('tidak boleh terpanggil')]);
        $this->app->instance(LlmClient::class, $fake);
        $this->forgetActor();
        $nonaktif = User::factory()->role(Role::StafGudang)->inactive()->create(['sppg_id' => $this->sppgA]);

        $this->webhook($this->privateUpdate((int) $nonaktif->telegram_id, ['text' => 'cek stok']))->assertOk();

        $this->assertStringContainsString('nonaktif', $this->messagesTo((int) $nonaktif->telegram_id)->sole()['text']);
        $this->assertSame([], $fake->calls);
    }

    public function test_pengguna_tanpa_sesi_login_penuh_diarahkan_masuk_lewat_mini_app(): void
    {
        $fake = new FakeLlmClient([LlmResponse::text('tidak boleh terpanggil')]);
        $this->app->instance(LlmClient::class, $fake);
        $this->forgetActor();
        $belumMasuk = User::factory()->role(Role::StafGudang)->create(['sppg_id' => $this->sppgA]);

        $this->webhook($this->privateUpdate((int) $belumMasuk->telegram_id, ['text' => 'cek stok beras']))->assertOk();

        $balasan = $this->messagesTo((int) $belumMasuk->telegram_id)->sole();
        $this->assertSame(TelegramUpdateHandler::PERLU_MASUK, $balasan['text']);
        $this->assertStringContainsString('web_app', (string) $balasan['reply_markup']);
        $this->assertSame([], $fake->calls);
        $this->assertSame(['ringkas' => true, 'jenis' => 'ai_tanpa_sesi'], TelegramUpdate::query()->sole()->payload);
    }

    public function test_sesi_dicabut_atau_password_direset_menghentikan_agen_ai_di_bot(): void
    {
        $fake = new FakeLlmClient([LlmResponse::text('tidak boleh terpanggil')]);
        $this->app->instance(LlmClient::class, $fake);
        $this->forgetActor();

        app(SesiService::class)->keluarSemua($this->kepalaA);
        $this->webhook($this->privateUpdate((int) $this->kepalaA->telegram_id, ['text' => 'halo']))->assertOk();

        $this->beriSesiPenuh($this->kepalaA);
        app(KredensialService::class)->resetOlehAdmin($this->admin, $this->kepalaA->fresh());
        $this->webhook($this->privateUpdate((int) $this->kepalaA->telegram_id, ['text' => 'halo lagi']))->assertOk();

        $this->assertSame([], $fake->calls);
        $this->assertCount(2, $this->messagesTo((int) $this->kepalaA->telegram_id)
            ->filter(fn ($r) => $r['text'] === TelegramUpdateHandler::PERLU_MASUK));
    }

    public function test_job_agen_ai_menolak_bila_sesi_dicabut_setelah_masuk_antrean(): void
    {
        $fake = new FakeLlmClient([LlmResponse::text('tidak boleh terpanggil')]);
        $this->app->instance(LlmClient::class, $fake);
        $update = TelegramUpdate::query()->create([
            'update_id' => 990001,
            'payload' => $this->privateUpdate((int) $this->kepalaA->telegram_id, ['text' => 'halo']),
        ]);

        app(SesiService::class)->keluarSemua($this->kepalaA);
        (new ProcessAiMessage($update->id, $this->kepalaA->id))->handle(app(AiChatHandler::class), app(SesiService::class));

        $this->assertSame([], $fake->calls);
        $this->assertSame('ai_tanpa_sesi', $update->fresh()->payload['jenis']);
    }

    public function test_pesan_teks_pengguna_terdaftar_diteruskan_ke_agen_ai_dan_dibalas_teks_polos(): void
    {
        $fake = new FakeLlmClient([LlmResponse::text('<b>Halo</b> *tebal* <a href="https://jahat.test">klik</a>')]);
        $this->app->instance(LlmClient::class, $fake);

        $this->webhook($this->privateUpdate((int) $this->kepalaA->telegram_id, ['text' => 'halo bot']))->assertOk();

        $this->assertCount(1, $fake->calls);
        $sent = $this->messagesTo((int) $this->kepalaA->telegram_id)->sole();
        // Keluaran model dikirim sebagai teks polos: tanpa parse_mode sehingga tidak dirender.
        $this->assertArrayNotHasKey('parse_mode', $sent->data());
        $this->assertSame('<b>Halo</b> *tebal* <a href="https://jahat.test">klik</a>', $sent['text']);
        $this->assertCount(1, $this->telegramCalls('sendChatAction'));
        $this->assertSame(['ringkas' => true, 'jenis' => 'ai'], TelegramUpdate::query()->sole()->payload);
    }

    public function test_foto_dikirim_ke_llm_sebagai_lampiran_gambar_dengan_caption_sebagai_data(): void
    {
        $fake = new FakeLlmClient([LlmResponse::text('Nota terbaca.')]);
        $this->app->instance(LlmClient::class, $fake);

        $this->webhook($this->privateUpdate((int) $this->kepalaA->telegram_id, [
            'photo' => [
                ['file_id' => 'kecil', 'file_size' => 5],
                ['file_id' => 'besar', 'file_size' => 12],
                ['file_id' => 'raksasa', 'file_size' => 50 * 1024 * 1024],
            ],
            'caption' => 'belanja sayur pasar pagi',
        ]))->assertOk();

        $this->assertSame('besar', $this->telegramCalls('getFile')->sole()['file_id']);
        $user = $fake->calls[0]['messages'][0];
        $this->assertSame('image/jpeg', $user['images'][0]['media_type']);
        $this->assertStringContainsString('belanja sayur pasar pagi', $user['content']);
        $this->assertStringContainsString('foto', $user['content']);
    }

    public function test_foto_bukan_gambar_ditolak_tanpa_memanggil_llm(): void
    {
        $fake = new FakeLlmClient;
        $this->app->instance(LlmClient::class, $fake);
        $this->fakeTelegram(['api.telegram.org/file/*' => Http::response('<?php echo 1;', 200)]);

        $this->webhook($this->privateUpdate((int) $this->kepalaA->telegram_id, ['photo' => [['file_id' => 'x', 'file_size' => 10]]]))->assertOk();

        $this->assertSame([], $fake->calls);
        $this->assertStringContainsString('Format foto tidak didukung', $this->messagesTo((int) $this->kepalaA->telegram_id)->sole()['text']);
    }

    public function test_pesan_suara_tanpa_stt_dibalas_belum_didukung(): void
    {
        $fake = new FakeLlmClient;
        $this->app->instance(LlmClient::class, $fake);

        $this->webhook($this->privateUpdate((int) $this->kepalaA->telegram_id, ['voice' => ['file_id' => 'v1', 'duration' => 5, 'mime_type' => 'audio/ogg']]))->assertOk();

        $this->assertSame([], $fake->calls);
        $this->assertStringContainsString('Pesan suara belum didukung', $this->messagesTo((int) $this->kepalaA->telegram_id)->sole()['text']);
    }

    public function test_pesan_suara_dengan_stt_http_ditranskripsi_sebagai_data(): void
    {
        config(['ai.stt.driver' => 'http', 'ai.stt.url' => 'https://stt.test/v1/audio/transcriptions', 'ai.stt.api_key' => 'stt-kunci-fiktif']);
        $fake = new FakeLlmClient([LlmResponse::text('Baik.')]);
        $this->app->instance(LlmClient::class, $fake);
        $this->fakeTelegram(['stt.test/*' => Http::response(['text' => 'log produksi selesai 240 porsi'])]);

        $this->webhook($this->privateUpdate((int) $this->kepalaA->telegram_id, ['voice' => ['file_id' => 'v1', 'duration' => 5, 'mime_type' => 'audio/ogg']]))->assertOk();

        $this->assertStringContainsString('transkrip pesan suara', $fake->calls[0]['messages'][0]['content']);
        $this->assertStringContainsString('log produksi selesai 240 porsi', $fake->calls[0]['messages'][0]['content']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'stt.test') && $r->hasHeader('Authorization', 'Bearer stt-kunci-fiktif'));
    }

    public function test_throttle_login_tidak_berlaku_untuk_webhook(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->webhook(['update_id' => 50000 + $i])->assertOk();
        }
    }
}
