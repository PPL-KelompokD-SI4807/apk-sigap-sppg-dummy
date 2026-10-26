<?php

namespace App\Enums;

enum KategoriPengeluaran: string
{
    case BahanBaku = 'bahan_baku';
    case InsentifRelawan = 'insentif_relawan';
    case Energi = 'energi';
    case Transportasi = 'transportasi';
    case Peralatan = 'peralatan';
    case Lainnya = 'lainnya';

    public function label(): string
    {
        return match ($this) {
            self::BahanBaku => 'Belanja Bahan',
            self::InsentifRelawan => 'Insentif Relawan',
            self::Energi => 'Energi (Gas/Listrik/Air)',
            self::Transportasi => 'Transportasi',
            self::Peralatan => 'Peralatan',
            self::Lainnya => 'Lainnya',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
