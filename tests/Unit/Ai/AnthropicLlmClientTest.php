<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\Llm\AnthropicLlmClient;
use App\Services\Ai\Llm\LlmClient;
use App\Services\Ai\Llm\LlmException;
use App\Services\Ai\Llm\NullLlmClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnthropicLlmClientTest extends TestCase
{
    private const KEY = 'sk-test-kunci-fiktif-jangan-bocor';

    private function client(): AnthropicLlmClient
    {
        return new AnthropicLlmClient(self::KEY, 'model-uji', 'https://llm.test', retries: 0);
    }

    public function test_provider_dipilih_dari_config(): void
    {
        config(['ai.provider' => '']);
        $this->assertInstanceOf(NullLlmClient::class, app(LlmClient::class));

        config(['ai.provider' => 'anthropic', 'ai.anthropic.api_key' => self::KEY]);
        $this->assertInstanceOf(AnthropicLlmClient::class, app(LlmClient::class));
    }

    public function test_tool_use_dan_gambar_dikonversi_serta_respons_diurai(): void
    {
        Http::fake(['llm.test/*' => Http::response([
            'model' => 'model-uji',
            'stop_reason' => 'tool_use',
            'content' => [
                ['type' => 'text', 'text' => 'Mencari...'],
                ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'cari_titik_layanan', 'input' => ['q' => 'SD']],
            ],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ])]);

        $response = $this->client()->chat([
            ['role' => 'user', 'content' => 'cari', 'images' => [['media_type' => 'image/png', 'data' => 'AAAA']]],
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'tu_0', 'name' => 'cari_titik_layanan', 'arguments' => []]]],
            ['role' => 'tool', 'tool_call_id' => 'tu_0', 'name' => 'cari_titik_layanan', 'content' => '{"status":"ok"}', 'is_error' => false],
        ], [['name' => 'cari_titik_layanan', 'description' => 'd', 'input_schema' => ['type' => 'object']]], ['system' => 'SISTEM']);

        $this->assertSame('Mencari...', $response->text);
        $this->assertSame('cari_titik_layanan', $response->toolCalls[0]->name);
        $this->assertSame(['q' => 'SD'], $response->toolCalls[0]->arguments);

        Http::assertSent(function (Request $r) {
            $body = $r->data();

            return $r->url() === 'https://llm.test/v1/messages'
                && $r->hasHeader('x-api-key', self::KEY)
                && $r->hasHeader('anthropic-version', '2023-06-01')
                && $body['system'] === 'SISTEM'
                && $body['messages'][0]['content'][0]['type'] === 'image'
                && $body['messages'][1]['content'][0]['type'] === 'tool_use'
                && $body['messages'][2]['content'][0]['type'] === 'tool_result'
                && $body['tools'][0]['input_schema'] === ['type' => 'object'];
        });
    }

    public function test_galat_tidak_membocorkan_kunci_maupun_body(): void
    {
        Http::fake(['llm.test/*' => Http::response(['error' => ['message' => 'prompt rahasia: '.self::KEY]], 401)]);

        try {
            $this->client()->chat([['role' => 'user', 'content' => 'x']], []);
            $this->fail('Seharusnya melempar LlmException.');
        } catch (LlmException $e) {
            $this->assertStringContainsString('HTTP 401', $e->getMessage());
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
            $this->assertStringNotContainsString('rahasia', $e->getMessage());
        }

        Http::fake(fn () => throw new ConnectionException('timeout untuk https://llm.test?key='.self::KEY));

        try {
            $this->client()->chat([['role' => 'user', 'content' => 'x']], []);
            $this->fail('Seharusnya melempar LlmException.');
        } catch (LlmException $e) {
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
        }
    }

    public function test_tanpa_kunci_tidak_mengirim_permintaan(): void
    {
        $this->expectException(LlmException::class);

        try {
            (new AnthropicLlmClient(null, 'm'))->chat([['role' => 'user', 'content' => 'x']], []);
        } finally {
            Http::assertNothingSent();
        }
    }
}
