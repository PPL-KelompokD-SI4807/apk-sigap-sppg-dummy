<?php

namespace App\Services\Notification;

use App\Enums\Role;

/**
 * Notifikasi bot siap kirim. `html` WAJIB sudah di-escape (TelegramBotService::escapeHtml) untuk
 * setiap bagian dinamis. Penerima = pengguna aktif ber-telegram_id dengan peran $roles di SPPG
 * $sppgId, ditambah $chatIds mentah (mis. PIC titik layanan; tanpa tombol Mini App).
 */
final class NotificationMessage
{
    /**
     * @param  list<Role>  $roles
     * @param  list<int>  $chatIds
     * @param  array{type: string, id: int}|null  $track  pelacakan status kirim (notifikasi_perjalanan / rekap_harian)
     */
    public function __construct(
        public readonly string $type,
        public readonly string $dedupKey,
        public readonly ?int $sppgId,
        public readonly string $html,
        public readonly array $roles = [],
        public readonly array $chatIds = [],
        public readonly ?string $buttonLabel = null,
        public readonly ?string $buttonPath = null,
        public readonly bool $fallbackToAdmin = false,
        public readonly ?array $track = null,
    ) {}
}
