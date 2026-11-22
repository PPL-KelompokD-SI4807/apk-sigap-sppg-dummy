<?php

namespace App\Enums;

enum KelompokSasaran: string
{
    case Siswa = 'siswa';
    case Balita = 'balita';
    case IbuHamil = 'ibu_hamil';
    case IbuMenyusui = 'ibu_menyusui';
    /** R07-1 (Q-8): baris Lampiran 30b-A; data lama tidak diubah. */
    case Pendidik = 'pendidik';
    case TenagaKependidikan = 'tenaga_kependidikan';
    case Ats = 'ats';

    public function label(): string
    {
        return match ($this) {
            self::Siswa => 'Siswa',
            self::Balita => 'Balita',
            self::IbuHamil => 'Ibu Hamil',
            self::IbuMenyusui => 'Ibu Menyusui',
            self::Pendidik => 'Pendidik',
            self::TenagaKependidikan => 'Tenaga Kependidikan',
            self::Ats => 'Anak Tidak Sekolah (ATS)',
        };
    }

    /**
     * Kelompok untuk Menu dan Standar Gizi (empat kelompok asli). Pendidik, tenaga kependidikan, dan ATS hanya
     * klasifikasi penerima/laporan BGN (R07-1); menu dan standar gizinya tidak berubah.
     *
     * @return list<self>
     */
    public static function untukGizi(): array
    {
        return [self::Siswa, self::Balita, self::IbuHamil, self::IbuMenyusui];
    }

    /** @return list<string> */
    public static function valuesGizi(): array
    {
        return array_map(fn (self $k) => $k->value, self::untukGizi());
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
