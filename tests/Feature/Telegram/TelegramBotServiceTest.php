<?php

namespace Tests\Feature\Telegram;

use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramBotServiceTest extends TestCase
{
    use FakesTelegram;

    private const TOKEN = '123456789:TEST-ONLY-NOT-A-REAL-BOT-TOKEN-sigap';

    public function test_galat_koneksi_tidak_membocorkan_token(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28 for https://api.telegram.org/bot'.self::TOKEN.'/sendMessage'));

        try {
            app(TelegramBotService::class)->sendText(1, 'x');
            $this->fail('Seharusnya melempar.');
        } catch (TelegramApiException $e) {
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
            $this->assertStringContainsString('bot***', $e->getMessage());
        }
    }

    public function test_galat_api_memuat_kode_dan_retry_after(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests', 'parameters' => ['retry_after' => 7]], 429)]);

        try {
            app(TelegramBotService::class)->sendText(1, 'x');
            $this->fail('Seharusnya melempar.');
        } catch (TelegramApiException $e) {
            $this->assertTrue($e->isRateLimited());
            $this->assertSame(7, $e->retryAfter);
        }
    }

    public function test_tombol_web_app_hanya_untuk_https_dan_pesan_dipotong(): void
    {
        $this->assertNull(TelegramBotService::webAppButton('Buka', 'http://localhost/miniapp/'));
        $this->assertSame(['text' => 'Buka', 'web_app' => ['url' => 'https://x.test/']], TelegramBotService::webAppButton('Buka', 'https://x.test/'));
        $this->assertNull(TelegramBotService::inlineKeyboard([null]));

        $this->fakeTelegram();
        app(TelegramBotService::class)->sendText(5, str_repeat('a', 5000));
        $this->assertSame(4096, mb_strlen($this->telegramCalls('sendMessage')->sole()['text']));
    }

    public function test_unduh_berkas_menolak_yang_terlalu_besar(): void
    {
        $this->fakeTelegram(['api.telegram.org/*/getFile' => Http::response(['ok' => true, 'result' => ['file_path' => 'a.jpg', 'file_size' => 999999]])]);

        $this->expectException(TelegramApiException::class);
        app(TelegramBotService::class)->downloadFile('f', 1000);
    }

    public function test_command_set_webhook_memakai_secret_dan_https_tanpa_mencetak_rahasia(): void
    {
        $this->fakeTelegram();
        config(['telegram.webhook_secret' => 'rahasia-webhook-fiktif-1234567890']);

        $this->artisan('sigap:telegram-set-webhook', ['--url' => 'https://sigap.test/api/v1/telegram/webhook'])
            ->expectsOutputToContain('Webhook terdaftar')
            ->doesntExpectOutputToContain('rahasia-webhook-fiktif')
            ->doesntExpectOutputToContain(self::TOKEN)
            ->assertSuccessful();

        $set = $this->telegramCalls('setWebhook')->sole();
        $this->assertSame('https://sigap.test/api/v1/telegram/webhook', $set['url']);
        $this->assertSame('rahasia-webhook-fiktif-1234567890', $set['secret_token']);
        $this->assertSame(['message', 'callback_query'], json_decode($set['allowed_updates'], true));
        $menu = json_decode($this->telegramCalls('setChatMenuButton')->sole()['menu_button'], true);
        $this->assertSame('web_app', $menu['type']);
        $this->assertSame('https://sigap.test/miniapp/', $menu['web_app']['url']);
    }

    public function test_command_menolak_http_dan_secret_lemah(): void
    {
        $this->fakeTelegram();
        config(['telegram.webhook_secret' => 'rahasia-webhook-fiktif-1234567890']);
        $this->artisan('sigap:telegram-set-webhook', ['--url' => 'http://sigap.test/hook'])->assertFailed();

        config(['telegram.webhook_secret' => 'pendek']);
        $this->artisan('sigap:telegram-set-webhook', ['--url' => 'https://sigap.test/hook'])->assertFailed();

        $this->assertCount(0, $this->telegramCalls('setWebhook'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'setWebhook'));
    }
}
