<?php

namespace App\Services\Ai\Llm;

/** Pemanggilan tool dari model. Argumen TIDAK tepercaya (divalidasi ketat oleh ArgumentValidator). */
final class ToolCall
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $arguments,
        /** False bila argumen dari penyedia bukan objek JSON sah. */
        public readonly bool $argumentsValid = true,
    ) {}

    /** @return array{id: string, name: string, arguments: array<string, mixed>} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'arguments' => $this->arguments];
    }
}
