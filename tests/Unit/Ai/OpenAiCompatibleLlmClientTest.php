<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\Llm\LlmClient;
use App\Services\Ai\Llm\LlmException;
use App\Services\Ai\Llm\NullLlmClient;
use App\Services\Ai\Llm\OpenAiCompatibleLlmClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Adapter Chat Completions kompatibel OpenAI (Groq/DeepSeek) dengan Http::fake (tanpa kunci nyata).
 */
class OpenAiCompatibleLlmClientTest extends TestCase
{
    private const KEY = 'gsk_kunci-fiktif-jangan-bocor-123';

    private const BASE = 'https://llm.test/openai/v1';

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    private function client(array $overrides = []): OpenAiCompatibleLlmClient
    {
        $args = $overrides + [
            'baseUrl' => self::BASE,
            'apiKey' => self::KEY,
            'model' => 'openai/gpt-oss-120b',
            'visionModel' => null,
            'retries' => 0,
        ];

        return new OpenAiCompatibleLlmClient(...$args);
    }

    /** @param array<string, mixed> $message */
    private static function completion(array $message, array $extra = []): array
    {
        return $extra + [
            'id' => 'chatcmpl-uji',
            'model' => 'openai/gpt-oss-120b',
            'choices' => [['index' => 0, 'message' => $message + ['role' => 'assistant'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 8, 'total_tokens' => 128],
        ];
    }

    private function assertTanpaRahasia(string $teks): void
    {
        $this->assertStringNotContainsString(self::KEY, $teks);
        $this->assertStringNotContainsString('rahasia', $teks);
    }

    // ------------------------------------------------------------------ binding & konfigurasi

    public function test_provider_openai_compat_dipilih_dari_config(): void
    {
        config(['ai.provider' => 'openai_compat', 'ai.openai_compat.base_url' => self::BASE, 'ai.openai_compat.api_key' => self::KEY, 'ai.openai_compat.model' => 'openai/gpt-oss-120b']);
        $client = app(LlmClient::class);

        $this->assertInstanceOf(OpenAiCompatibleLlmClient::class, $client);
        $this->assertSame('openai/gpt-oss-120b', $client->modelName());
        $this->assertFalse($client->supportsVision(), 'LLM_VISION_MODEL kosong = gambar tidak didukung.');

        config(['ai.provider' => '']);
        $this->assertInstanceOf(NullLlmClient::class, app(LlmClient::class));
    }

    public function test_base_url_tanpa_default_penyedia(): void
    {
        config(['ai.openai_compat.base_url' => null, 'ai.openai_compat.api_key' => self::KEY, 'ai.openai_compat.model' => 'm']);

        $this->assertFalse(OpenAiCompatibleLlmClient::fromConfig()->isConfigured());
    }

    /** @return array<string, array{0: ?string}> */
    public static function urlTidakSah(): array
    {
        return [
            'kosong' => [''],
            'null' => [null],
            'http' => ['http://api.groq.com/openai/v1'],
            'userinfo' => ['https://user:pass@api.groq.com/openai/v1'],
            'query' => ['https://api.groq.com/openai/v1?redirect=https://jahat.test'],
            'fragmen' => ['https://api.groq.com/openai/v1#x'],
            'skema lain' => ['file:///etc/passwd'],
            'spasi' => ['https://api.groq.com/openai/v1 https://jahat.test'],
        ];
    }

    #[DataProvider('urlTidakSah')]
    public function test_base_url_tidak_sah_ditolak_tanpa_mengirim(?string $url): void
    {
        Http::fake();

        try {
            $this->client(['baseUrl' => $url])->chat([['role' => 'user', 'content' => 'x']], []);
            $this->fail('Seharusnya melempar LlmException.');
        } catch (LlmException $e) {
            $this->assertSame(LlmException::KONFIGURASI, $e->category);
        }

        Http::assertNothingSent();
    }

    public function test_tanpa_kunci_atau_model_tidak_mengirim(): void
    {
        Http::fake();

        foreach ([['apiKey' => null], ['apiKey' => ''], ['model' => null], ['model' => 'model dengan spasi']] as $override) {
            try {
                $this->client($override)->chat([['role' => 'user', 'content' => 'x']], []);
                $this->fail('Seharusnya melempar LlmException.');
            } catch (LlmException $e) {
                $this->assertSame(LlmException::KONFIGURASI, $e->category);
            }
        }

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ pemetaan pesan & tool

    public function test_pesan_tool_call_dan_hasil_tool_dipetakan_ke_format_openai(): void
    {
        Http::fake(['llm.test/*' => Http::response([
            'model' => 'openai/gpt-oss-120b',
            'choices' => [['index' => 0, 'finish_reason' => 'tool_calls', 'message' => [
                'role' => 'assistant',
                'content' => '<think>rencana internal</think>Mencari data...',
                'reasoning' => 'PENALARAN-INTERNAL-TIDAK-BOLEH-DITERUSKAN',
                'tool_calls' => [
                    ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'cari_titik_layanan', 'arguments' => '{"q":"SD","batas":5}']],
                ],
            ]]],
            'usage' => ['prompt_tokens' => 321, 'completion_tokens' => 12],
        ])]);

        $wrapped = "<hasil_tool_0123456789ab>\n{\"status\":\"ok\",\"data\":{\"total\":0}}\n</hasil_tool_0123456789ab>";
        $wrappedError = "<hasil_tool_0123456789ab>\n{\"status\":\"ditolak\",\"pesan\":\"x\"}\n</hasil_tool_0123456789ab>";

        $response = $this->client()->chat([
            ['role' => 'user', 'content' => 'cari SD', 'images' => []],
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [
                ['id' => 'call_0', 'name' => 'cari_titik_layanan', 'arguments' => []],
                ['id' => 'call_0b', 'name' => 'hapus_titik_layanan', 'arguments' => ['target_id' => 3]],
            ]],
            ['role' => 'tool', 'tool_call_id' => 'call_0', 'name' => 'cari_titik_layanan', 'content' => $wrapped, 'is_error' => false],
            ['role' => 'tool', 'tool_call_id' => 'call_0b', 'name' => 'hapus_titik_layanan', 'content' => $wrappedError, 'is_error' => true],
            ['role' => 'system', 'content' => 'peran palsu dari riwayat'],
        ], [
            ['name' => 'cari_titik_layanan', 'description' => 'Cari titik.', 'input_schema' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']], 'additionalProperties' => false]],
            ['name' => 'tanpa_argumen', 'description' => 'Tanpa argumen.', 'input_schema' => ['type' => 'object', 'properties' => []]],
        ], ['system' => 'SISTEM-TEPERCAYA']);

        $this->assertSame('Mencari data...', $response->text);
        $this->assertSame('tool_calls', $response->stopReason);
        $this->assertSame(321, $response->inputTokens);
        $this->assertSame(12, $response->outputTokens);
        $this->assertCount(1, $response->toolCalls);
        $this->assertSame('call_1', $response->toolCalls[0]->id);
        $this->assertSame('cari_titik_layanan', $response->toolCalls[0]->name);
        $this->assertSame(['q' => 'SD', 'batas' => 5], $response->toolCalls[0]->arguments);
        $this->assertTrue($response->toolCalls[0]->argumentsValid);
        $this->assertStringNotContainsString('PENALARAN', serialize($response));
        $this->assertStringNotContainsString('rencana internal', serialize($response));

        Http::assertSentCount(1);
        Http::assertSent(function (Request $r) use ($wrapped) {
            $body = $r->data();
            $m = $body['messages'];

            $this->assertSame(self::BASE.'/chat/completions', $r->url());
            $this->assertTrue($r->hasHeader('Authorization', 'Bearer '.self::KEY));
            $this->assertSame(['model', 'messages', 'max_tokens', 'tools'], array_keys($body), 'Hanya parameter umum yang dikirim.');
            $this->assertSame(['role' => 'system', 'content' => 'SISTEM-TEPERCAYA'], $m[0]);
            $this->assertSame(['role' => 'user', 'content' => 'cari SD'], $m[1]);
            $this->assertSame('assistant', $m[2]['role']);
            $this->assertNull($m[2]['content']);
            $this->assertSame(['id' => 'call_0', 'type' => 'function', 'function' => ['name' => 'cari_titik_layanan', 'arguments' => '{}']], $m[2]['tool_calls'][0]);
            $this->assertSame('{"target_id":3}', $m[2]['tool_calls'][1]['function']['arguments']);
            // Hasil tool tetap utuh dalam blok bernonce; galat ditandai DI DALAM blok.
            $this->assertSame(['role' => 'tool', 'tool_call_id' => 'call_0', 'content' => $wrapped], $m[3]);
            $this->assertMatchesRegularExpression('#^<hasil_tool_0123456789ab>\n\[galat_tool\] \{"status":"ditolak".*\n</hasil_tool_0123456789ab>$#s', $m[4]['content']);
            $this->assertArrayNotHasKey('is_error', $m[4]);
            // Pesan berperan 'system' dari riwayat diturunkan menjadi pengguna (tidak bisa menyamar sistem).
            $this->assertSame('user', $m[5]['role']);
            $this->assertSame(['type' => 'function', 'function' => ['name' => 'cari_titik_layanan', 'description' => 'Cari titik.', 'parameters' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']], 'additionalProperties' => false]]], $body['tools'][0]);
            $this->assertStringContainsString('"properties":{}', $r->body(), 'properties kosong dikirim sebagai objek JSON.');
            $this->assertStringNotContainsString('reasoning', $r->body());

            return true;
        });
    }

    public function test_tanpa_tool_tidak_mengirim_kunci_tools_dan_konten_array_dibaca(): void
    {
        Http::fake(['llm.test/*' => Http::response(self::completion(['content' => [['type' => 'text', 'text' => 'siap']], 'reasoning_content' => 'R1-RAHASIA']))]);

        $response = $this->client()->chat([['role' => 'user', 'content' => 'balas dengan kata: siap']], []);

        $this->assertSame('siap', $response->text);
        Http::assertSent(fn (Request $r) => ! array_key_exists('tools', $r->data()) && $r->data()['messages'][0]['role'] === 'user');
    }

    /** @return array<string, array{0: mixed, 1: bool}> */
    public static function argumenMentah(): array
    {
        return [
            'objek sah' => ['{"q":"SD"}', true],
            'objek kosong' => ['{}', true],
            'string kosong' => ['', true],
            'JSON terpotong' => ['{"q": "SD"', false],
            'bukan JSON' => ['q=SD; DROP TABLE', false],
            'array JSON' => ['[1,2]', false],
            'string JSON' => ['"hapus semua"', false],
            'angka' => ['42', false],
            'null JSON' => ['null', false],
            'tipe salah' => [123, false],
            'array asosiatif (penyedia lain)' => [['q' => 'SD'], true],
            'list' => [[1, 2], false],
        ];
    }

    #[DataProvider('argumenMentah')]
    public function test_argumen_tool_json_rusak_ditandai_tidak_sah(mixed $raw, bool $valid): void
    {
        [$arguments, $ok] = OpenAiCompatibleLlmClient::decodeArguments($raw);

        $this->assertSame($valid, $ok);

        if (! $valid) {
            $this->assertSame([], $arguments);
        }
    }

    public function test_tool_call_rusak_dari_respons_tidak_sah_dan_id_ganda_diganti(): void
    {
        Http::fake(['llm.test/*' => Http::response(self::completion(['content' => null, 'tool_calls' => [
            ['id' => 'call_x', 'type' => 'function', 'function' => ['name' => 'buat_titik_layanan', 'arguments' => '{"nama": "SD']],
            ['id' => 'call_x', 'type' => 'function', 'function' => ['name' => 'cari_titik_layanan', 'arguments' => '{}']],
            ['id' => 'call_y', 'type' => 'function', 'function' => ['arguments' => '{}']],
            ['id' => 'call_z', 'type' => 'code_interpreter', 'function' => ['name' => 'x', 'arguments' => '{}']],
        ]]))]);

        $response = $this->client()->chat([['role' => 'user', 'content' => 'x']], []);

        $this->assertCount(2, $response->toolCalls);
        $this->assertFalse($response->toolCalls[0]->argumentsValid);
        $this->assertSame([], $response->toolCalls[0]->arguments);
        $this->assertTrue($response->toolCalls[1]->argumentsValid);
        $this->assertNotSame($response->toolCalls[0]->id, $response->toolCalls[1]->id);
        $this->assertSame('', $response->text);
    }

    public function test_respons_bukan_chat_completion_ditolak(): void
    {
        Http::fake(['llm.test/*' => Http::response('<html>bukan json</html>', 200)]);

        try {
            $this->client()->chat([['role' => 'user', 'content' => 'x']], []);
            $this->fail('Seharusnya melempar LlmException.');
        } catch (LlmException $e) {
            $this->assertSame(LlmException::RESPONS_RUSAK, $e->category);
        }
    }

    public function test_gambar_tidak_dikirim_lewat_chat(): void
    {
        Http::fake();

        $this->expectException(LlmException::class);

        try {
            $this->client()->chat([['role' => 'user', 'content' => 'x', 'images' => [['media_type' => 'image/png', 'data' => 'AAAA']]]], []);
        } finally {
            Http::assertNothingSent();
        }
    }

    // ------------------------------------------------------------------ galat, retry, redirect

    /** @return array<string, array{0: int, 1: string}> */
    public static function statusGalat(): array
    {
        return [
            '401' => [401, LlmException::KONFIGURASI],
            '403' => [403, LlmException::KONFIGURASI],
            '402' => [402, LlmException::KUOTA],
            '413' => [413, LlmException::TERLALU_BESAR],
            '429' => [429, LlmException::SIBUK],
            '400' => [400, LlmException::TIDAK_TERSEDIA],
            '500' => [500, LlmException::TIDAK_TERSEDIA],
            '503' => [503, LlmException::TIDAK_TERSEDIA],
        ];
    }

    #[DataProvider('statusGalat')]
    public function test_galat_http_jadi_kategori_ramah_tanpa_isi_respons_maupun_kunci(int $status, string $category): void
    {
        Http::fake(['llm.test/*' => Http::response(['error' => ['message' => 'prompt rahasia pengguna; key='.self::KEY]], $status, ['X-Echo' => self::KEY])]);

        try {
            $this->client()->chat([['role' => 'user', 'content' => 'rahasia pengguna']], []);
            $this->fail('Seharusnya melempar LlmException.');
        } catch (LlmException $e) {
            $this->assertSame($category, $e->category);
            $this->assertSame($status, $e->status);
            $this->assertStringContainsString('formulir', $e->friendlyMessage());
            $this->assertSame('llm:'.$category.' http='.$status, $e->logLabel());
            $this->assertTanpaRahasia($e->getMessage().$e->friendlyMessage().$e->logLabel().(string) $e);
        }

        Http::assertSentCount(1);
    }

    public function test_timeout_jadi_galat_ramah_tanpa_kunci(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28 timeout https://llm.test?key='.self::KEY));

        try {
            $this->client()->chat([['role' => 'user', 'content' => 'x']], []);
            $this->fail('Seharusnya melempar LlmException.');
        } catch (LlmException $e) {
            $this->assertSame(LlmException::TIDAK_TERSEDIA, $e->category);
            $this->assertSame('timeout', $e->detail);
            $this->assertNull($e->getPrevious());
            $this->assertTanpaRahasia($e->getMessage().$e->logLabel().(string) $e);
        }
    }

    public function test_retry_maksimal_satu_kali_walau_config_lebih(): void
    {
        Http::fake(['llm.test/*' => Http::response(['error' => 'x'], 503)]);

        try {
            $this->client(['retries' => 5])->chat([['role' => 'user', 'content' => 'x']], []);
            $this->fail('Seharusnya melempar LlmException.');
        } catch (LlmException $e) {
            $this->assertSame(503, $e->status);
        }

        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
    }

    public function test_galat_koneksi_dicoba_ulang_sekali_lalu_sukses(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            if (++$calls === 1) {
                throw new ConnectionException('reset');
            }

            return Http::response(self::completion(['content' => 'siap']));
        });

        $this->assertSame('siap', $this->client(['retries' => 1])->chat([['role' => 'user', 'content' => 'x']], [])->text);
        $this->assertSame(2, $calls);
    }

    public function test_retry_after_pendek_dihormati(): void
    {
        Http::fakeSequence('llm.test/*')
            ->push(['error' => 'rate'], 429, ['Retry-After' => '2'])
            ->push(self::completion(['content' => 'siap']));

        $response = $this->client(['retries' => 1])->chat([['role' => 'user', 'content' => 'x']], [], ['deadline' => microtime(true) + 25]);

        $this->assertSame('siap', $response->text);
        Http::assertSentCount(2);
        Sleep::assertSequence([Sleep::for(2000)->milliseconds()]);
    }

    public function test_retry_after_panjang_atau_melewati_anggaran_tidak_ditunggu(): void
    {
        // Retry-After 10 dtk > batas 3 dtk -> langsung sibuk.
        Http::fake(['llm.test/*' => Http::response(['error' => 'rate'], 429, ['Retry-After' => '10'])]);

        try {
            $this->client(['retries' => 1])->chat([['role' => 'user', 'content' => 'x']], []);
            $this->fail('Seharusnya melempar LlmException.');
        } catch (LlmException $e) {
            $this->assertSame(LlmException::SIBUK, $e->category);
        }

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    public function test_retry_after_dalam_batas_tetapi_anggaran_waktu_habis_tidak_ditunggu(): void
    {
        Http::fake(['llm.test/*' => Http::response(['error' => 'rate'], 429, ['Retry-After' => '3'])]);

        try {
            $this->client(['retries' => 1])->chat([['role' => 'user', 'content' => 'x']], [], ['deadline' => microtime(true) + 3.5]);
            $this->fail('Seharusnya melempar LlmException.');
        } catch (LlmException $e) {
            $this->assertSame(LlmException::SIBUK, $e->category);
        }

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    public function test_429_dengan_retry_after_sangat_panjang_dianggap_kuota_habis(): void
    {
        Http::fake(['llm.test/*' => Http::response(['error' => 'tpd'], 429, ['Retry-After' => '3600'])]);

        try {
            $this->client(['retries' => 1])->chat([['role' => 'user', 'content' => 'x']], []);
            $this->fail('Seharusnya melempar LlmException.');
        } catch (LlmException $e) {
            $this->assertSame(LlmException::KUOTA, $e->category);
        }
    }

    public function test_anggaran_waktu_habis_tidak_mengirim(): void
    {
        Http::fake();

        try {
            $this->client()->chat([['role' => 'user', 'content' => 'x']], [], ['deadline' => microtime(true) + 0.2]);
            $this->fail('Seharusnya melempar LlmException.');
        } catch (LlmException $e) {
            $this->assertSame('timeout', $e->detail);
        }

        Http::assertNothingSent();
    }

    public function test_redirect_tidak_diikuti(): void
    {
        Http::fake([
            'llm.test/*' => Http::response('', 302, ['Location' => 'https://jahat.test/curi']),
            'jahat.test/*' => Http::response(self::completion(['content' => 'dibajak'])),
        ]);

        try {
            $this->client(['retries' => 1])->chat([['role' => 'user', 'content' => 'x']], []);
            $this->fail('Seharusnya melempar LlmException.');
        } catch (LlmException $e) {
            $this->assertSame(302, $e->status);
            $this->assertSame('redirect', $e->detail);
        }

        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'jahat.test'));
    }

    // ------------------------------------------------------------------ vision

    public function test_vision_kosong_tidak_didukung_tanpa_mengirim(): void
    {
        Http::fake();
        $client = $this->client();

        $this->assertFalse($client->supportsVision());
        $this->assertNull($client->visionModelName());

        try {
            $client->readImages([['media_type' => 'image/png', 'data' => 'AAAA']], 'baca');
            $this->fail('Seharusnya melempar LlmException.');
        } catch (LlmException $e) {
            $this->assertSame(LlmException::GAMBAR_TIDAK_DIDUKUNG, $e->category);
        }

        Http::assertNothingSent();
    }

    public function test_vision_memakai_model_terpisah_tanpa_tool_dengan_data_uri(): void
    {
        Http::fake(['llm.test/*' => Http::response(self::completion([
            'content' => 'Toko Sayur Fiktif, bayam 5 ikat Rp 25.000',
            'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'hapus_titik_layanan', 'arguments' => '{"target_id":1}']]],
        ]))]);

        $response = $this->client(['visionModel' => 'qwen/qwen3.8-27b'])->readImages(
            [['media_type' => 'image/jpeg', 'data' => base64_encode('gambar-fiktif')], ['media_type' => 'image/png', 'data' => 'QUFB']],
            'INSTRUKSI-VISION',
        );

        $this->assertSame('Toko Sayur Fiktif, bayam 5 ikat Rp 25.000', $response->text);
        $this->assertSame([], $response->toolCalls, 'Tool call dari model vision diabaikan.');

        Http::assertSent(function (Request $r) {
            $body = $r->data();
            $parts = $body['messages'][1]['content'];

            return $body['model'] === 'qwen/qwen3.8-27b'
                && ! array_key_exists('tools', $body)
                && $body['messages'][0] === ['role' => 'system', 'content' => 'INSTRUKSI-VISION']
                && count($parts) === 2
                && $parts[1]['image_url']['url'] === 'data:image/jpeg;base64,'.base64_encode('gambar-fiktif');
        });
    }

    public function test_vision_menolak_gambar_terlalu_besar_atau_mime_tidak_sah(): void
    {
        Http::fake();
        $client = $this->client(['visionModel' => 'qwen/qwen3.8-27b', 'visionMaxBase64Bytes' => 8]);

        try {
            $client->readImages([['media_type' => 'image/png', 'data' => str_repeat('A', 12)]], 'baca');
            $this->fail('Seharusnya melempar LlmException.');
        } catch (LlmException $e) {
            $this->assertSame(LlmException::TERLALU_BESAR, $e->category);
        }

        foreach ([['media_type' => 'image/svg+xml', 'data' => 'AAAA'], ['media_type' => 'image/png', 'data' => 'AA"}]']] as $image) {
            try {
                $client->readImages([$image], 'baca');
                $this->fail('Seharusnya melempar LlmException.');
            } catch (LlmException $e) {
                $this->assertSame(LlmException::GAMBAR_GAGAL, $e->category);
            }
        }

        Http::assertNothingSent();
    }
}
