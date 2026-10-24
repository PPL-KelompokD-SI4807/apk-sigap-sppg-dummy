<?php

namespace App\Enums;

enum StatusOperasional: string
{
    case Aktif = 'aktif';
    case Libur = 'libur';
    case Nonaktif = 'nonaktif';

    public function label(): string
    {
        return match ($this) {
            self::Aktif => 'Aktif',
            self::Libur => 'Libur',
            self::Nonaktif => 'Nonaktif',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
