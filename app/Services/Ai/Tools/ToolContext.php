<?php

namespace App\Services\Ai\Tools;

use App\Enums\JenisInputAi;

/** Konteks eksekusi tool (interaksi AI yang sedang berjalan). */
final class ToolContext
{
    public function __construct(
        public readonly ?int $interactionId = null,
        public readonly JenisInputAi $inputType = JenisInputAi::Teks,
    ) {}
}
