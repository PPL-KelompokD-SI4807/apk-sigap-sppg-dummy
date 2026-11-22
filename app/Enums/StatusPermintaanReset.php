<?php

namespace App\Enums;

/**
 * Status permintaan reset password (R-03). "kedaluwarsa" tidak disimpan: dihitung dari
 * kedaluwarsa_pada untuk permintaan yang masih menunggu/disetujui (lihat PermintaanResetPassword).
 */
enum StatusPermintaanReset: string
{
    case Menunggu = 'menunggu';
    case Disetujui = 'disetujui';
    case Ditolak = 'ditolak';
    case Selesai = 'selesai';

    public const KEDALUWARSA = 'kedaluwarsa';

    public function label(): string
    {
        return match ($this) {
            self::Menunggu => 'Menunggu persetujuan Admin',
            self::Disetujui => 'Disetujui',
            self::Ditolak => 'Ditolak',
            self::Selesai => 'Selesai',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
