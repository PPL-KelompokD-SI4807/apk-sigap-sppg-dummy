<?php

namespace App\Enums;

/** Jenis kelamin penerima manfaat (Lampiran 30b-A/B, 30d-C). Hanya agregat yang masuk laporan. */
enum JenisKelamin: string
{
    case LakiLaki = 'laki_laki';
    case Perempuan = 'perempuan';

    public function label(): string
    {
        return match ($this) {
            self::LakiLaki => 'Laki-laki',
            self::Perempuan => 'Perempuan',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
