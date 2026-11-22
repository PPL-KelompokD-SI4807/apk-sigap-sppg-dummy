<?php

namespace App\Enums;

/** Jenjang setingkat baris Lampiran 30b-A (SLB/pesantren menyatu ke baris jenjangnya; lihat JenisSatuanPendidikan). */
enum JenjangPenerima: string
{
    case PaudTk = 'paud_tk';
    case Sd1a3 = 'sd_1_3';
    case Sd4a6 = 'sd_4_6';
    case Smp = 'smp';
    case Sma = 'sma';
    case AtsKurang9 = 'ats_kurang_9';
    case Ats9a18 = 'ats_9_18';

    public function label(): string
    {
        return match ($this) {
            self::PaudTk => 'PAUD/TK/RA/TKLB',
            self::Sd1a3 => 'SD/MI/SD SLB Kelas 1-3',
            self::Sd4a6 => 'SD/MI/SD SLB Kelas 4-6',
            self::Smp => 'SMP/MTs/SMP SLB/Pesantren Kelas 1-3',
            self::Sma => 'SMA/MA/SMK/SMA SLB/Pesantren Kelas 4-6',
            self::AtsKurang9 => 'ATS Usia < 9 tahun',
            self::Ats9a18 => 'ATS Usia 9-18 Tahun',
        };
    }

    public function untukAts(): bool
    {
        return $this === self::AtsKurang9 || $this === self::Ats9a18;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
