<?php

namespace App\Enums;

enum KategoriKeluhan: string
{
    case MakananBasi = 'makanan_basi';
    case GejalaSakit = 'gejala_sakit';
    case PorsiKurang = 'porsi_kurang';
    case Terlambat = 'terlambat';
    case Rasa = 'rasa';
    case Kemasan = 'kemasan';
    case Lainnya = 'lainnya';

    public function label(): string
    {
        return match ($this) {
            self::MakananBasi => 'Makanan Basi',
            self::GejalaSakit => 'Gejala Sakit',
            self::PorsiKurang => 'Porsi Kurang',
            self::Terlambat => 'Pengiriman Terlambat',
            self::Rasa => 'Rasa/Selera',
            self::Kemasan => 'Kemasan',
            self::Lainnya => 'Lainnya',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
