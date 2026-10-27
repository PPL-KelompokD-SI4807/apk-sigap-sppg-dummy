<?php

namespace App\Services\Telegram\Bot;

/** Satu layar pesan Bot: teks (HTML, bagian dinamis sudah di-escape) + keyboard inline opsional. */
final class BotLayar
{
    /** @param array<string, mixed>|null $markup */
    public function __construct(
        public readonly string $teks,
        public readonly ?array $markup = null,
        public readonly bool $html = true,
    ) {}
}
