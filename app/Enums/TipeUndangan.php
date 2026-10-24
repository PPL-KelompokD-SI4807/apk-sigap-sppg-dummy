<?php

namespace App\Enums;

enum TipeUndangan: string
{
    case Pengguna = 'pengguna';
    case PicTitikLayanan = 'pic';

    public function label(): string
    {
        return match ($this) {
            self::Pengguna => 'Pengguna',
            self::PicTitikLayanan => 'PIC Titik Layanan',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
