<?php

namespace App\Enums;

enum StatusJadwalDistribusi: string
{
    case Menunggu = 'menunggu';
    case Siap = 'siap';
    case Berangkat = 'berangkat';
    case Diterima = 'diterima';
    case Dibatalkan = 'dibatalkan';

    public function label(): string
    {
        return match ($this) {
            self::Menunggu => 'Menunggu',
            self::Siap => 'Siap Berangkat',
            self::Berangkat => 'Berangkat',
            self::Diterima => 'Diterima',
            self::Dibatalkan => 'Dibatalkan',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
