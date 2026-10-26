<?php

namespace App\Services\Ai\Llm;

/** Respons LLM ternormalisasi: teks dan/atau daftar tool call. */
final class LlmResponse
{
    /**
     * @param  list<ToolCall>  $toolCalls
     */
    public function __construct(
        public readonly string $text = '',
        public readonly array $toolCalls = [],
        public readonly ?string $stopReason = null,
        public readonly ?string $model = null,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
    ) {}

    public static function text(string $text): self
    {
        return new self($text, [], 'end_turn');
    }

    /**
     * Satu atau beberapa tool call (untuk FakeLlmClient/tes).
     *
     * @param  array<string, mixed>  $arguments
     */
    public static function toolCall(string $name, array $arguments = [], ?string $id = null, string $text = ''): self
    {
        return new self($text, [new ToolCall($id ?? 'call_'.bin2hex(random_bytes(4)), $name, $arguments)], 'tool_use');
    }

    /** @param list<ToolCall> $calls */
    public static function toolCalls(array $calls, string $text = ''): self
    {
        return new self($text, $calls, 'tool_use');
    }

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }
}
