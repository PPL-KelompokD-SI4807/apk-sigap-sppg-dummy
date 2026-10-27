<?php

namespace App\Services\Telegram\Bot\Layar;

use App\Models\User;
use App\Services\Telegram\Bot\BotLayarRegistry;

/** Bawaan aman untuk layar baca: hak R penuh, butuh SPPG, tanpa jeda, tanpa pemeriksaan tambahan. */
abstract class LayarBacaDasar implements LayarBaca
{
    public function hak(): string
    {
        return self::HAK_BACA;
    }

    public function induk(): ?string
    {
        return BotLayarRegistry::BERANDA;
    }

    public function butuhSppg(): bool
    {
        return true;
    }

    public function jedaDetik(): int
    {
        return 0;
    }

    public function izinkan(User $user, ?int $sppgId): bool
    {
        return true;
    }
}
