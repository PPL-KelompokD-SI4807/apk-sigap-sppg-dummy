<?php

namespace App\Enums;

enum JenisPemasok: string
{
    case Umkm = 'umkm';
    case Petani = 'petani';
    case Peternak = 'peternak';
    case Nelayan = 'nelayan';
    case Koperasi = 'koperasi';

    public function label(): string
    {
        return match ($this) {
            self::Umkm => 'UMKM',
            self::Petani => 'Petani',
            self::Peternak => 'Peternak',
            self::Nelayan => 'Nelayan',
            self::Koperasi => 'Koperasi',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
