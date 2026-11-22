<?php

namespace App\Services\Telegram\Bot;

/**
 * Isi token navigasi `n1` yang MAC-nya sah. Hanya penunjuk (layar + SPPG yang diminta), BUKAN hak:
 * BotNavigasi::buka memverifikasi ulang semuanya pada setiap klik.
 */
final class BotNavTujuan
{
    public function __construct(
        public readonly string $layar,
        public readonly ?int $sppgId,
        public readonly int $exp,
        public readonly bool $kedaluwarsa,
    ) {}
}
