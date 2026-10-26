<?php

namespace Tests\Feature\Telegram;

use App\Services\Ai\Llm\LlmClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Master\MasterApiTestCase;

/**
 * Bot Telegram dengan provider openai_compat (Groq): foto tanpa model vision dibalas ramah sebelum
 * diunduh, dan pesan suara ditranskripsi lewat STT bergaya Groq.
 */
class TelegramOpenAiCompatTest extends MasterApiTestCase
{
    use FakesTelegram;

    private const KEY = 'gsk_kunci-fiktif-telegram';

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeTelegram();
        $this->beriSesiPenuh($this->kepalaA);
        config([
            'ai.provider' => 'openai_compat',
            'ai.openai_compat.base_url' => 'https://llm.test/openai/v1',
            'ai.openai_compat.api_key' => self::KEY,
            'ai.openai_compat.model' => 'openai/gpt-oss-120b',
            'ai.openai_compat.vision_model' => '',
        ]);
        $this->app->forgetInstance(LlmClient::class);
    }

    public function test_foto_tanpa_model_vision_dibalas_ramah_sebelum_mengunduh(): void
    {
        $this->webhook($this->privateUpdate((int) $this->kepalaA->telegram_id, [
            'photo' => [['file_id' => 'foto1', 'file_size' => 12]],
            'caption' => 'nota belanja',
        ]))->assertOk();

        $this->assertCount(0, $this->telegramCalls('getFile'), 'Foto tidak diunduh.');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'llm.test'));

        $sent = $this->messagesTo((int) $this->kepalaA->telegram_id)->sole();
        $this->assertStringContainsString('Membaca foto belum didukung', $sent['text']);
        $this->assertStringContainsString('formulir', $sent['text']);
        $this->assertNotNull($this->markupOf($sent), 'Tombol formulir Mini App ditawarkan.');
    }

    public function test_pesan_suara_ditranskripsi_stt_groq_lalu_diproses_model_teks(): void
    {
        config([
            'ai.stt.driver' => 'http',
            'ai.stt.url' => 'https://api.groq.com/openai/v1/audio/transcriptions',
            'ai.stt.api_key' => 'gsk_stt-fiktif',
            'ai.stt.model' => 'whisper-large-v3-turbo',
        ]);
        $this->fakeTelegram([
            'api.groq.com/*' => Http::response(['text' => ' log produksi selesai 240 porsi ', 'x_groq' => ['id' => 'req_1']]),
            'llm.test/*' => Http::response([
                'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Baik.'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2],
            ]),
        ]);

        $this->webhook($this->privateUpdate((int) $this->kepalaA->telegram_id, ['voice' => ['file_id' => 'v1', 'duration' => 5, 'mime_type' => 'audio/ogg']]))->assertOk();

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://api.groq.com/openai/v1/audio/transcriptions')
            && $r->hasHeader('Authorization', 'Bearer gsk_stt-fiktif')
            && $r->isMultipart());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'llm.test')
            && str_contains((string) $r->data()['messages'][1]['content'], 'log produksi selesai 240 porsi'));
        $this->assertSame('Baik.', $this->messagesTo((int) $this->kepalaA->telegram_id)->sole()['text']);
    }
}
