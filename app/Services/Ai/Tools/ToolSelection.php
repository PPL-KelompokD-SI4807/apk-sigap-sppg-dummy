<?php

namespace App\Services\Ai\Tools;

/**
 * Hasil ToolSelector: tool yang DITAWARKAN ke model untuk satu pesan (selalu subset
 * ToolRegistry::forUser()) beserta skemanya. Tool di luar $tools ditolak walau masih hak peran.
 */
final class ToolSelection
{
    /**
     * @param  array<string, AiTool>  $tools
     * @param  list<array{name: string, description: string, input_schema: array<string, mixed>}>  $schemas
     * @param  list<string>  $otherModules  label modul hak peran yang tidak ditawarkan (untuk prompt)
     */
    public function __construct(
        public readonly array $tools,
        public readonly array $schemas,
        public readonly bool $active,
        public readonly int $fullTokens,
        public readonly int $selectedTokens,
        public readonly array $otherModules = [],
    ) {}
}
