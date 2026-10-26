<?php

namespace App\Providers;

use App\Listeners\KirimNotifikasiDomain;
use App\Services\Ai\Llm\AnthropicLlmClient;
use App\Services\Ai\Llm\FakeLlmClient;
use App\Services\Ai\Llm\LlmClient;
use App\Services\Ai\Llm\NullLlmClient;
use App\Services\Ai\Llm\OpenAiCompatibleLlmClient;
use App\Services\Ai\Speech\HttpSpeechToText;
use App\Services\Ai\Speech\NullSpeechToText;
use App\Services\Ai\Speech\SpeechToTextService;
use App\Services\Ai\Tools\ToolRegistry;
use App\Services\Notification\DomainEventNotifications;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Integrasi Telegram Bot + Agen AI + notifikasi terpusat.
 *
 * - LlmClient dipilih dari config('ai.provider'): anthropic | openai_compat | fake (non-production) | '' (Null).
 * - SpeechToTextService dari config('ai.stt.driver'): http | '' (Null).
 * - Listener KirimNotifikasiDomain hanya didaftarkan untuk kelas event yang ada saat boot.
 * - Rate limiter: telegram-webhook (per IP) dan telegram-out (kirim keluar, NFR-03).
 * - Saat unit test: Http::preventStrayRequests() agar tidak ada panggilan jaringan nyata
 *   (Bot API/LLM) dari tes modul mana pun; tes yang butuh HTTP memakai Http::fake().
 */
class SigapIntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LlmClient::class, function ($app) {
            return match ((string) config('ai.provider')) {
                'anthropic' => AnthropicLlmClient::fromConfig(),
                'openai_compat' => OpenAiCompatibleLlmClient::fromConfig(),
                'fake' => $app->isProduction() ? new NullLlmClient : $app->make(FakeLlmClient::class),
                default => new NullLlmClient,
            };
        });

        $this->app->singleton(FakeLlmClient::class, fn () => new FakeLlmClient);

        $this->app->bind(SpeechToTextService::class, fn () => match ((string) config('ai.stt.driver')) {
            'http' => HttpSpeechToText::fromConfig(),
            default => new NullSpeechToText,
        });

        $this->app->singleton(ToolRegistry::class);
    }

    public function boot(): void
    {
        foreach (DomainEventNotifications::registeredEventClasses() as $eventClass) {
            Event::listen($eventClass, KirimNotifikasiDomain::class);
        }

        // Webhook Telegram: server Telegram memakai sedikit IP; batas longgar per IP.
        RateLimiter::for('telegram-webhook', fn (Request $request) => Limit::perMinute(600)
            ->by('tg:'.$request->ip()));

        // Kirim keluar: ~30 pesan/detik global (pedoman Bot API).
        RateLimiter::for('telegram-out', fn () => Limit::perSecond(25)->by('telegram-out'));

        if ($this->app->runningUnitTests()) {
            Http::preventStrayRequests();
        }
    }
}
