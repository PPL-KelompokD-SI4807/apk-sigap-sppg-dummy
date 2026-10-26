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
    /** Pemilik SPPG (R-06a): baca saja, dapat memiliki banyak SPPG lewat pivot investor_sppg. */
    case Investor = 'investor';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::KepalaSppg => 'Kepala SPPG',
            self::AhliGizi => 'Ahli Gizi',
            self::Akuntan => 'Akuntan',
            self::StafGudang => 'Staf Gudang',
            self::Relawan => 'Relawan',
            self::Investor => 'Investor',
        };
    }

    /** Admin satu-satunya peran yang tidak dibatasi SPPG. */
    public function isCrossSppg(): bool
    {
        return $this === self::Admin;
    }

    /**
     * Peran yang users.sppg_id-nya WAJIB null: Admin (lintas SPPG) dan Investor (SPPG dari pivot
     * investor_sppg, satu SPPG aktif per permintaan; lihat App\Support\SppgAktif).
     */
    public function tanpaSppgId(): bool
    {
        return $this === self::Admin || $this === self::Investor;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
