<?php

namespace App\Enums;

/**
 * Peran pengguna SiGAP SPPG (Karakteristik Pengguna, Tabel 4 proposal).
 * Nilai string dipakai di kolom users.role dan sebagai kunci matriks config/rbac.php.
 */
enum Role: string
{
    case Admin = 'admin';
    case KepalaSppg = 'kepala_sppg';
    case AhliGizi = 'ahli_gizi';
    case Akuntan = 'akuntan';
    case StafGudang = 'staf_gudang';
    case Relawan = 'relawan';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::KepalaSppg => 'Kepala SPPG',
            self::AhliGizi => 'Ahli Gizi',
            self::Akuntan => 'Akuntan',
            self::StafGudang => 'Staf Gudang',
            self::Relawan => 'Relawan',
        };
    }

    /** Admin satu-satunya peran yang tidak dibatasi SPPG. */
    public function isCrossSppg(): bool
    {
        return $this === self::Admin;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
