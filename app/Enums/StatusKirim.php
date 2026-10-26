<?php

namespace App\Enums;

enum StatusKirim: string
{
    case Menunggu = 'menunggu';
    case Terkirim = 'terkirim';
    case Gagal = 'gagal';
    case TidakTerkirim = 'tidak_terkirim';

    public function label(): string
    {
        return match ($this) {
            self::Menunggu => 'Menunggu',
            self::Terkirim => 'Terkirim',
            self::Gagal => 'Gagal',
            self::TidakTerkirim => 'Tidak Terkirim',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
