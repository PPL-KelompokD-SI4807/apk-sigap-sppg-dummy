<?php

namespace App\Services\Ai\Llm;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * LlmClient untuk Anthropic Messages API (function calling = blok tool_use/tool_result,
 * gambar = blok image base64).
 *
 * Keamanan: kunci API hanya dari config('ai.anthropic.api_key') (env LLM_API_KEY) dan hanya
 * dikirim di header x-api-key. Exception dibungkus LlmException tanpa kunci, tanpa isi pesan,
 * dan tanpa body respons mentah. Timeout dan retry terbatas (hanya galat koneksi/429/5xx).
 */
class AnthropicLlmClient implements LlmClient
{
    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model,
        private readonly string $baseUrl = 'https://api.anthropic.com',
        private readonly string $version = '2023-06-01',
        private readonly int $maxTokens = 1024,
        private readonly int $timeout = 20,
        private readonly int $connectTimeout = 5,
        private readonly int $retries = 1,
        private readonly int $retrySleepMs = 500,
    ) {}

    public static function fromConfig(): self
    {
        $c = (array) config('ai.anthropic');

        return new self(
            apiKey: isset($c['api_key']) && is_string($c['api_key']) ? $c['api_key'] : null,
            model: (string) ($c['model'] ?? 'claude-sonnet-4-5'),
            baseUrl: (string) ($c['base_url'] ?? 'https://api.anthropic.com'),
            version: (string) ($c['version'] ?? '2023-06-01'),
            maxTokens: (int) ($c['max_tokens'] ?? 1024),
            timeout: (int) ($c['timeout'] ?? 20),
            connectTimeout: (int) ($c['connect_timeout'] ?? 5),
            retries: (int) ($c['retries'] ?? 1),
            retrySleepMs: (int) ($c['retry_sleep_ms'] ?? 500),
        );
    }

    public function modelName(): string
    {
        return $this->model;
    }

    public function chat(array $messages, array $tools, array $options = []): LlmResponse
    {
        if ($this->apiKey === null || $this->apiKey === '') {
            throw LlmException::notConfigured();
        }

        $body = array_filter([
            'model' => $this->model,
            'max_tokens' => (int) ($options['max_tokens'] ?? $this->maxTokens),
            'system' => isset($options['system']) ? (string) $options['system'] : null,
            'messages' => $this->convertMessages($messages),
            'tools' => $tools === [] ? null : array_map(fn (array $t) => [
                'name' => $t['name'],
                'description' => $t['description'],
                'input_schema' => $t['input_schema'],
            ], $tools),
        ], fn ($v) => $v !== null);

        try {
            $response = Http::baseUrl(rtrim($this->baseUrl, '/'))
                ->withHeaders(['x-api-key' => $this->apiKey, 'anthropic-version' => $this->version])
                ->acceptJson()
                ->asJson()
                ->timeout((int) ($options['timeout'] ?? $this->timeout))
                ->connectTimeout($this->connectTimeout)
                ->retry(
                    $this->retries + 1,
                    $this->retrySleepMs,
                    fn (Throwable $e) => $e instanceof ConnectionException
                        || ($e instanceof RequestException && ($e->response->status() === 429 || $e->response->serverError())),
                    throw: false,
                )
                ->post('/v1/messages', $body);
        } catch (ConnectionException) {
            throw LlmException::timeout();
        } catch (Throwable $e) {
            throw new LlmException('Permintaan ke layanan LLM gagal ('.class_basename($e).').');
        }

        return $this->parse($response);
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    public function convertMessages(array $messages): array
    {
        $out = [];
        $pendingResults = [];

        $flushResults = function () use (&$out, &$pendingResults): void {
            if ($pendingResults !== []) {
                $out[] = ['role' => 'user', 'content' => $pendingResults];
                $pendingResults = [];
            }
        };

        foreach ($messages as $m) {
            $role = $m['role'] ?? 'user';

            if ($role === 'tool') {
                $pendingResults[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => (string) ($m['tool_call_id'] ?? ''),
                    'content' => (string) ($m['content'] ?? ''),
                    'is_error' => (bool) ($m['is_error'] ?? false),
                ];

                continue;
            }

            $flushResults();

            if ($role === 'assistant') {
                $blocks = [];

                if (is_string($m['content'] ?? null) && trim($m['content']) !== '') {
                    $blocks[] = ['type' => 'text', 'text' => $m['content']];
                }

                foreach ($m['tool_calls'] ?? [] as $call) {
                    $args = $call['arguments'] ?? [];
                    $blocks[] = [
                        'type' => 'tool_use',
                        'id' => (string) $call['id'],
                        'name' => (string) $call['name'],
                        'input' => $args === [] ? new \stdClass : $args,
                    ];
                }

                $out[] = ['role' => 'assistant', 'content' => $blocks === [] ? [['type' => 'text', 'text' => '.']] : $blocks];

                continue;
            }

            $blocks = [];

            foreach ($m['images'] ?? [] as $image) {
                $blocks[] = [
                    'type' => 'image',
                    'source' => ['type' => 'base64', 'media_type' => (string) $image['media_type'], 'data' => (string) $image['data']],
                ];
            }

            $blocks[] = ['type' => 'text', 'text' => (string) ($m['content'] ?? '')];
            $out[] = ['role' => 'user', 'content' => $blocks];
        }

        $flushResults();

        return $out;
    }

    private function parse(Response $response): LlmResponse
    {
        if (! $response->successful()) {
            // Body galat penyedia tidak diteruskan (bisa memuat potongan prompt).
            throw LlmException::fromStatus($response->status());
        }

        $json = $response->json();

        if (! is_array($json) || ! is_array($json['content'] ?? null)) {
            throw LlmException::invalidResponse();
        }

        $text = [];
        $calls = [];

        foreach ($json['content'] as $block) {
            $type = $block['type'] ?? null;

            if ($type === 'text' && is_string($block['text'] ?? null)) {
                $text[] = $block['text'];
            } elseif ($type === 'tool_use' && is_string($block['name'] ?? null) && is_string($block['id'] ?? null)) {
                $input = $block['input'] ?? null;
                $calls[] = new ToolCall($block['id'], $block['name'], is_array($input) ? $input : [], is_array($input));
            }
        }

        return new LlmResponse(
            text: implode("\n", $text),
            toolCalls: $calls,
            stopReason: is_string($json['stop_reason'] ?? null) ? $json['stop_reason'] : null,
            model: is_string($json['model'] ?? null) ? $json['model'] : $this->model,
            inputTokens: (int) ($json['usage']['input_tokens'] ?? 0),
            outputTokens: (int) ($json['usage']['output_tokens'] ?? 0),
        );
    }
}
