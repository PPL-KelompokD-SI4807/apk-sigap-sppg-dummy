<?php

namespace App\Services\Telegram\Bot;

/**
 * Satu layar pesan Bot: teks (HTML, bagian dinamis sudah di-escape) + keyboard inline opsional.
 * `lindungi` = kirim dengan protect_content (cegah forward/simpan) untuk pesan berisi data. Telegram hanya
 * menerapkannya saat pesan DIKIRIM (editMessageText tidak bisa menambahkannya), jadi pesan akar navigasi
 * (/menu, /start, perintah pintasan) wajib dikirim terlindungi.
 */
final class BotLayar
{
    /** @param array<string, mixed>|null $markup */
    public function __construct(
        public readonly string $teks,
        public readonly ?array $markup = null,
        public readonly bool $html = true,
        public readonly bool $lindungi = false,
    ) {}

    public function terlindungi(): self
    {
        return new self($this->teks, $this->markup, $this->html, true);
    }
}
