<?php

namespace App\Enums;

enum HasilMutu: string
{
    case Layak = 'layak';
    case TidakLayak = 'tidak_layak';

    public function label(): string
    {
        return match ($this) {
            self::Layak => 'Layak',
            self::TidakLayak => 'Tidak Layak',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
