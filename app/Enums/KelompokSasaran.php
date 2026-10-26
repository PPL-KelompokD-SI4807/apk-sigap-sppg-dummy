<?php

namespace App\Enums;

enum KelompokSasaran: string
{
    case Siswa = 'siswa';
    case Balita = 'balita';
    case IbuHamil = 'ibu_hamil';
    case IbuMenyusui = 'ibu_menyusui';

    public function label(): string
    {
        return match ($this) {
            self::Siswa => 'Siswa',
            self::Balita => 'Balita',
            self::IbuHamil => 'Ibu Hamil',
            self::IbuMenyusui => 'Ibu Menyusui',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
