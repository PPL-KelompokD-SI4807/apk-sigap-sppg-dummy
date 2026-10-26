<?php

namespace App\Enums;

enum KategoriBahan: string
{
    case Karbohidrat = 'karbohidrat';
    case ProteinHewani = 'protein_hewani';
    case ProteinNabati = 'protein_nabati';
    case Sayur = 'sayur';
    case Buah = 'buah';
    case Susu = 'susu';
    case Bumbu = 'bumbu';
    case Lainnya = 'lainnya';

    public function label(): string
    {
        return match ($this) {
            self::Karbohidrat => 'Karbohidrat',
            self::ProteinHewani => 'Protein Hewani',
            self::ProteinNabati => 'Protein Nabati',
            self::Sayur => 'Sayur',
            self::Buah => 'Buah',
            self::Susu => 'Susu',
            self::Bumbu => 'Bumbu',
            self::Lainnya => 'Lainnya',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
