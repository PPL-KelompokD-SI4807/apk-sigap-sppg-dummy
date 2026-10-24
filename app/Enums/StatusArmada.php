<?php

namespace App\Enums;

enum StatusArmada: string
{
    case Tersedia = 'tersedia';
    case Bertugas = 'bertugas';
    case Servis = 'servis';

    public function label(): string
    {
        return match ($this) {
            self::Tersedia => 'Tersedia',
            self::Bertugas => 'Bertugas',
            self::Servis => 'Servis',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
