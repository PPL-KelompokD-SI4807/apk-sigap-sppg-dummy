<?php

namespace App\Services\Telegram;

use RuntimeException;

/**
 * Galat Bot API yang aman untuk di-log: pesan tidak pernah memuat token bot (URL disamarkan).
 */
class TelegramApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $errorCode = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $errorCode ?? 0);
    }

    /** Pengguna memblokir bot / chat tidak ada: jangan dicoba ulang. */
    public function isPermanent(): bool
    {
        return in_array($this->errorCode, [400, 403], true);
    }

    public function isRateLimited(): bool
    {
        return $this->errorCode === 429;
    }
}
