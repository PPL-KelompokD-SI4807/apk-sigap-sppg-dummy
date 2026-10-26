<?php

namespace App\Services\Ai\Tools;

use RuntimeException;

/** Tool tidak dikenal atau tidak diizinkan untuk pengguna. */
class ToolException extends RuntimeException
{
    public const UNKNOWN = 'tidak_dikenal';

    public const FORBIDDEN = 'ditolak';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
