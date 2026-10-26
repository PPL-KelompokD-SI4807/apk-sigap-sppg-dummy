<?php

namespace App\Enums;

enum StatusJadwalProduksi: string
{
    case Direncanakan = 'direncanakan';
    case Dimasak = 'dimasak';
    case Selesai = 'selesai';
    case Dibatalkan = 'dibatalkan';

    public function label(): string
    {
        return match ($this) {
            self::Direncanakan => 'Direncanakan',
            self::Dimasak => 'Sedang Dimasak',
            self::Selesai => 'Selesai',
            self::Dibatalkan => 'Dibatalkan',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
