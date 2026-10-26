<?php

namespace App\Enums;

enum StatusAiDraft: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Discarded = 'discarded';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu Konfirmasi',
            self::Confirmed => 'Dikonfirmasi',
            self::Discarded => 'Dibuang',
            self::Expired => 'Kedaluwarsa',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
