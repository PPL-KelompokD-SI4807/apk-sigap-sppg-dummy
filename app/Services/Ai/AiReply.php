<?php

namespace App\Services\Ai;

use App\Models\AiDraft;

/**
 * Balasan Agen AI. `text` = teks POLOS (dikirim tanpa parse_mode sehingga tidak dirender
 * sebagai HTML/Markdown). `drafts` = draf yang perlu dikonfirmasi lewat tombol Mini App.
 * `fallback` = AI gagal/tidak tersedia -> bot menawarkan formulir manual.
 * `mediaTerkirim` = gambar pesan ini benar-benar dikirim ke penyedia (vision/model) -> pemberitahuan privasi boleh dicatat.
 */
final class AiReply
{
    /**
     * @param  list<AiDraft>  $drafts
     */
    public function __construct(
        public readonly string $text,
        public readonly array $drafts = [],
        public readonly bool $fallback = false,
        public readonly ?int $interactionId = null,
        public readonly bool $mediaTerkirim = false,
    ) {}
}
