<?php

namespace App\Enums;

/**
 * Baris sasaran penerima manfaat Lampiran 30b (A peserta didik, B non-peserta didik) dan kolom 30d-C
 * (urutan baku juknis). SLB dan pesantren menyatu ke baris jenjangnya.
 */
enum BarisSasaranBgn: string
{
    case PaudTk = 'paud_tk';
    case Sd1a3 = 'sd_1_3';
    case Sd4a6 = 'sd_4_6';
    case Smp = 'smp';
    case Sma = 'sma';
    case AtsKurang9 = 'ats_kurang_9';
    case Ats9a18 = 'ats_9_18';
    case Pendidik = 'pendidik';
    case TenagaKependidikan = 'tenaga_kependidikan';
    case IbuHamil = 'ibu_hamil';
    case IbuMenyusui = 'ibu_menyusui';
    case Balita = 'balita';

    public function label(): string
    {
        return match ($this) {
            self::PaudTk => JenjangPenerima::PaudTk->label(),
            self::Sd1a3 => JenjangPenerima::Sd1a3->label(),
            self::Sd4a6 => JenjangPenerima::Sd4a6->label(),
            self::Smp => JenjangPenerima::Smp->label(),
            self::Sma => JenjangPenerima::Sma->label(),
            self::AtsKurang9 => JenjangPenerima::AtsKurang9->label(),
            self::Ats9a18 => JenjangPenerima::Ats9a18->label(),
            self::Pendidik => 'Pendidik',
            self::TenagaKependidikan => 'Tenaga Kependidikan',
            self::IbuHamil => 'Ibu Hamil',
            self::IbuMenyusui => 'Ibu Menyusui',
            self::Balita => 'Balita (Non PAUD / 6-59 bulan)',
        };
    }

    public function pesertaDidik(): bool
    {
        return ! in_array($this, [self::IbuHamil, self::IbuMenyusui, self::Balita], true);
    }

    /** Baris sasaran dari klasifikasi penerima; null = belum terklasifikasi (tidak ditebak). */
    public static function dari(KelompokSasaran $kelompok, ?JenjangPenerima $jenjang): ?self
    {
        return match ($kelompok) {
            KelompokSasaran::Balita => self::Balita,
            KelompokSasaran::IbuHamil => self::IbuHamil,
            KelompokSasaran::IbuMenyusui => self::IbuMenyusui,
            KelompokSasaran::Pendidik => self::Pendidik,
            KelompokSasaran::TenagaKependidikan => self::TenagaKependidikan,
            KelompokSasaran::Siswa, KelompokSasaran::Ats => match ($jenjang) {
                JenjangPenerima::PaudTk => self::PaudTk,
                JenjangPenerima::Sd1a3 => self::Sd1a3,
                JenjangPenerima::Sd4a6 => self::Sd4a6,
                JenjangPenerima::Smp => self::Smp,
                JenjangPenerima::Sma => self::Sma,
                JenjangPenerima::AtsKurang9 => self::AtsKurang9,
                JenjangPenerima::Ats9a18 => self::Ats9a18,
                null => null,
            },
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
