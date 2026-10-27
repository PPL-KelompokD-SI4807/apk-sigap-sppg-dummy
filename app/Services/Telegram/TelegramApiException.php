<?php

namespace App\Services\Telegram;

use RuntimeException;

/**
 * Galat Bot API yang aman untuk di-log: pesan tidak pernah memuat token bot (URL disamarkan).
 */
class TelegramApiException extends RuntimeException
{
    /** Nilai $jenis: edit pesan ditolak karena teks + keyboard identik dengan yang sudah tampil. */
    public const JENIS_TIDAK_BERUBAH = 'message_not_modified';

    public function __construct(
        string $message,
        public readonly ?int $errorCode = null,
        public readonly ?int $retryAfter = null,
        public readonly ?string $jenis = null,
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

    /**
     * "Bad Request: message is not modified": edit tidak mengubah apa pun. Dikenali dari kelas galat
     * (jenis) yang ditetapkan TelegramBotService saat membaca deskripsi Telegram, bukan dari isi pesan pengguna.
     */
    public function isMessageNotModified(): bool
    {
        return $this->errorCode === 400 && $this->jenis === self::JENIS_TIDAK_BERUBAH;
    }
}
