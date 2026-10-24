<?php

namespace App\Enums;

enum JenisInputAi: string
{
    case Teks = 'teks';
    case Foto = 'foto';
    case Suara = 'suara';

    public function label(): string
    {
        return match ($this) {
            self::Teks => 'Teks',
            self::Foto => 'Foto',
            self::Suara => 'Suara',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
