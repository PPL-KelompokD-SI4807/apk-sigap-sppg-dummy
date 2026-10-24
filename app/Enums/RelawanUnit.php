<?php

namespace App\Enums;

/** Unit tugas relawan: dapur (Staf Dapur pada PBI) atau distribusi (Petugas Distribusi/Kurir). */
enum RelawanUnit: string
{
    case Dapur = 'dapur';
    case Distribusi = 'distribusi';

    public function label(): string
    {
        return match ($this) {
            self::Dapur => 'Dapur',
            self::Distribusi => 'Distribusi',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
