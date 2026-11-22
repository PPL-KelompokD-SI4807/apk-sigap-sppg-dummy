<?php

namespace App\Enums;

/**
 * Jenis satuan pendidikan penerima (pemisah 15 kelompok Lampiran 30a). Kosong (null) = reguler/belum
 * diklasifikasikan; `reguler` dipakai bila sengaja dinyatakan reguler.
 */
enum JenisSatuanPendidikan: string
{
    case Reguler = 'reguler';
    case Slb = 'slb';
    case Pesantren = 'pesantren';
    case PendidikanKhusus = 'pendidikan_khusus';
    case Informal = 'informal';

    public function label(): string
    {
        return match ($this) {
            self::Reguler => 'Reguler',
            self::Slb => 'SLB',
            self::Pesantren => 'Pesantren (Santri)',
            self::PendidikanKhusus => 'Pendidikan khusus lainnya',
            self::Informal => 'Pendidikan informal',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
