<?php

namespace App\Enums;

enum SumberKeluhan: string
{
    case Guru = 'guru';
    case OrangTua = 'orang_tua';
    case Penerima = 'penerima';
    case Relawan = 'relawan';
    case Lainnya = 'lainnya';

    public function label(): string
    {
        return match ($this) {
            self::Guru => 'Guru',
            self::OrangTua => 'Orang Tua',
            self::Penerima => 'Penerima Manfaat',
            self::Relawan => 'Relawan',
            self::Lainnya => 'Lainnya',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
