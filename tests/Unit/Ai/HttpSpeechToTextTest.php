<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\Speech\HttpSpeechToText;
use App\Services\Ai\Speech\NullSpeechToText;
use App\Services\Ai\Speech\SpeechToTextException;
use App\Services\Ai\Speech\SpeechToTextService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** STT bergaya OpenAI /audio/transcriptions dengan konfigurasi Groq (Http::fake, kunci fiktif). */
class HttpSpeechToTextTest extends TestCase
{
    private const KEY = 'gsk_stt-kunci-fiktif-jangan-bocor';

    private const URL = 'https://api.groq.com/openai/v1/audio/transcriptions';

    private function groq(): HttpSpeechToText
    {
        config(['ai.stt.driver' => 'http', 'ai.stt.url' => self::URL, 'ai.stt.api_key' => self::KEY, 'ai.stt.model' => 'whisper-large-v3-turbo']);

        $stt = app(SpeechToTextService::class);
        $this->assertInstanceOf(HttpSpeechToText::class, $stt);

        return $stt;
    }

    public function test_transkripsi_format_groq(): void
    {
        Http::fake(['api.groq.com/*' => Http::response(['text' => ' Catat beras masuk 20 kilo. ', 'x_groq' => ['id' => 'req_x']])]);

        $this->assertSame('Catat beras masuk 20 kilo.', $this->groq()->transcribe("OggS\x00fiktif", 'audio/ogg'));

        Http::assertSent(function (Request $r) {
            $parts = collect($r->data())->keyBy('name');

            return $r->url() === self::URL
                && $r->method() === 'POST'
                && $r->isMultipart()
                && $r->hasHeader('Authorization', 'Bearer '.self::KEY)
                && $parts['model']['contents'] === 'whisper-large-v3-turbo'
                && $parts['language']['contents'] === 'id'
                && $parts['file']['filename'] === 'voice.ogg';
        });
    }

    /** @return array<string, array{0: int, 1: string}> */
    public static function galat(): array
    {
        return [
            '401' => [401, 'belum dikonfigurasi'],
            '413' => [413, 'terlalu besar'],
            '429' => [429, 'sedang sibuk'],
            '500' => [500, 'tidak dapat ditranskripsi'],
        ];
    }

    #[DataProvider('galat')]
    public function test_galat_jadi_pesan_ramah_tanpa_kunci_atau_body(int $status, string $pesan): void
    {
        Http::fake(['api.groq.com/*' => Http::response(['error' => ['message' => 'rahasia '.self::KEY]], $status)]);

        try {
            $this->groq()->transcribe('audio');
            $this->fail('Seharusnya melempar SpeechToTextException.');
        } catch (SpeechToTextException $e) {
            $this->assertStringContainsString($pesan, $e->getMessage());
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
            $this->assertStringNotContainsString('rahasia', $e->getMessage());
        }
    }

    public function test_redirect_tidak_diikuti(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response('', 307, ['Location' => 'https://jahat.test/stt']),
            'jahat.test/*' => Http::response(['text' => 'dibajak']),
        ]);

        $this->expectException(SpeechToTextException::class);

        try {
            $this->groq()->transcribe('audio');
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_url_tidak_sah_tidak_tersedia(): void
    {
        Http::fake();

        foreach (['http://api.groq.com/openai/v1/audio/transcriptions', 'https://u:p@api.groq.com/x', 'https://api.groq.com/x?y=1', ''] as $url) {
            $stt = new HttpSpeechToText($url, self::KEY);
            $this->assertFalse($stt->isAvailable());

            try {
                $stt->transcribe('audio');
                $this->fail('Seharusnya melempar SpeechToTextException.');
            } catch (SpeechToTextException $e) {
                $this->assertSame(NullSpeechToText::MESSAGE, $e->getMessage());
            }
        }

        Http::assertNothingSent();
    }
}
