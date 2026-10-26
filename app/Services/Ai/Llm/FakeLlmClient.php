<?php

namespace App\Services\Ai\Llm;

use Closure;

/**
 * LLM palsu berskrip untuk tes. Setiap panggilan chat() mengambil langkah berikutnya dari skrip
 * (LlmResponse, Throwable untuk dilempar, atau Closure(messages, tools, options): LlmResponse).
 * Bila skrip habis, memakai $fallback (default: teks "Selesai.").
 *
 *   $fake = new FakeLlmClient([LlmResponse::toolCall('cari_titik_layanan', ['q' => 'SD']), LlmResponse::text('Ada 2.')]);
 *   app()->instance(LlmClient::class, $fake);
 *   ... $fake->calls[0]['tools'] berisi skema tool yang ditawarkan.
 */
class FakeLlmClient implements LlmClient
{
    /** @var list<array{messages: array, tools: array, options: array}> */
    public array $calls = [];

    /**
     * @param  list<LlmResponse|\Throwable|Closure>  $script
     */
    public function __construct(
        private array $script = [],
        private LlmResponse|Closure|null $fallback = null,
    ) {}

    public function push(LlmResponse|\Throwable|Closure $step): static
    {
        $this->script[] = $step;

        return $this;
    }

    /** Selalu membalas langkah yang sama (mis. tool call tanpa henti untuk uji pemotongan loop). */
    public function always(LlmResponse|Closure $step): static
    {
        $this->fallback = $step;

        return $this;
    }

    public function chat(array $messages, array $tools, array $options = []): LlmResponse
    {
        $this->calls[] = ['messages' => $messages, 'tools' => $tools, 'options' => $options];

        $step = $this->script === [] ? ($this->fallback ?? LlmResponse::text('Selesai.')) : array_shift($this->script);

        if ($step instanceof \Throwable) {
            throw $step;
        }

        if ($step instanceof Closure) {
            $step = $step($messages, $tools, $options);
        }

        return $step;
    }

    public function modelName(): string
    {
        return 'fake';
    }

    /** @return list<string> nama tool yang ditawarkan pada panggilan ke-$i. */
    public function offeredTools(int $i = 0): array
    {
        return array_map(fn (array $t) => $t['name'], $this->calls[$i]['tools'] ?? []);
    }
}
