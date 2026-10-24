<?php

namespace App\Services\Ai\Llm;

/** Agen AI nonaktif (config ai.provider kosong): selalu memicu fallback formulir manual. */
class NullLlmClient implements LlmClient
{
    public function chat(array $messages, array $tools, array $options = []): LlmResponse
    {
        throw LlmException::notConfigured();
    }

    public function modelName(): string
    {
        return 'none';
    }
}
