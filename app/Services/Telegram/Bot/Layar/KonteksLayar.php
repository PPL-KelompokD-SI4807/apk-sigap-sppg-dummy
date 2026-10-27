<?php

namespace App\Services\Telegram\Bot\Layar;

use App\Services\Telegram\Bot\BotLayarRegistry;
use App\Services\Telegram\Bot\BotNavCodec;

/**
 * Konteks render satu layar baca: chat tujuan, telegram_id pemilik tombol, catatan opsional, dan halaman
 * (indeks 0; layar berdaftar memakai id berakhiran 2-4 untuk halaman ke-2 sampai ke-4, lihat BotLayarRegistry::pecahId).
 */
final class KonteksLayar
{
    public function __construct(
        public readonly int $chatId,
        public readonly int $telegramId,
        public readonly ?string $catatan = null,
        public readonly int $halaman = 0,
    ) {}

    public function denganHalaman(int $halaman): self
    {
        return new self($this->chatId, $this->telegramId, $this->catatan, max(0, $halaman));
    }

    /**
     * Tombol navigasi `n1` milik pengguna ini. Tidak memberi hak apa pun: tujuan diperiksa ulang saat diklik.
     *
     * @return array{text: string, callback_data: string}
     */
    public function tombol(string $label, string $layar, ?int $sppgId): array
    {
        return BotNavCodec::tombol($label, $layar, $sppgId, $this->telegramId);
    }

    /**
     * Tombol ke halaman tertentu dari layar $layar (id dasar), halaman 0 = id dasar.
     *
     * @return array{text: string, callback_data: string}
     */
    public function tombolHalaman(string $label, string $layar, ?int $sppgId, int $halaman): array
    {
        return $this->tombol($label, BotLayarRegistry::idHalaman($layar, $halaman), $sppgId);
    }
}
