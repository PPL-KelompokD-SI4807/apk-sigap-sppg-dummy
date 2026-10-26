<?php

namespace App\Services\Ai\Llm;

/**
 * Antarmuka penyedia LLM (KT-11). Implementasi: AnthropicLlmClient (HTTP), FakeLlmClient (tes),
 * NullLlmClient (AI nonaktif). Dipilih lewat config('ai.provider').
 *
 * Format pesan ternormalisasi (tidak bergantung penyedia):
 *   ['role' => 'user', 'content' => string, 'images' => [['media_type' => 'image/jpeg', 'data' => base64]]]
 *   ['role' => 'assistant', 'content' => ?string, 'tool_calls' => [['id' => .., 'name' => .., 'arguments' => array]]]
 *   ['role' => 'tool', 'tool_call_id' => string, 'name' => string, 'content' => string, 'is_error' => bool]
 *
 * Tool: ['name' => string, 'description' => string, 'input_schema' => JSON Schema object].
 * Opsi: system (string), max_tokens (int), timeout (int detik).
 */
interface LlmClient
{
    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array{name: string, description: string, input_schema: array<string, mixed>}>  $tools
     * @param  array<string, mixed>  $options
     *
     * @throws LlmException
     */
    public function chat(array $messages, array $tools, array $options = []): LlmResponse;

    /** Nama model untuk dicatat di ai_interactions (bukan rahasia). */
    public function modelName(): string;
}
