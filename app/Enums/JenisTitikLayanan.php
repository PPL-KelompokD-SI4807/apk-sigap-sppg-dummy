<?php

namespace App\Enums;

enum JenisTitikLayanan: string
{
    case Sekolah = 'sekolah';
    case Paud = 'paud';
    case Posyandu = 'posyandu';
    case Lainnya = 'lainnya';

    public function label(): string
    {
        return match ($this) {
            self::Sekolah => 'Sekolah',
            self::Paud => 'PAUD',
            self::Posyandu => 'Posyandu',
            self::Lainnya => 'Lainnya',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
