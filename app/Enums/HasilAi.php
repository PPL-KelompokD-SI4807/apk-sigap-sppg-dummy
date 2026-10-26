<?php

namespace App\Enums;

enum HasilAi: string
{
    case Sukses = 'sukses';
    case Gagal = 'gagal';
    case Ditolak = 'ditolak';
    case Fallback = 'fallback';

    public function label(): string
    {
        return match ($this) {
            self::Sukses => 'Sukses',
            self::Gagal => 'Gagal',
            self::Ditolak => 'Ditolak',
            self::Fallback => 'Fallback Manual',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
