<?php

namespace App\Enums;

/**
 * 15 kelompok penerima manfaat pada Lampiran 30a (Bab 4.7 juknis; urutan baku). Acuan bahan baku per porsi:
 * kelompok "kecil" (Anak Balita, PAUD/TK/RA, SD/MI kelas 1-3) memakai acuan kecil SPPG (bawaan Rp8.000),
 * lainnya memakai acuan lainnya (bawaan Rp10.000).
 */
enum KelompokPorsiBgn: string
{
    case AnakBalita = 'anak_balita';
    case PaudTk = 'paud_tk';
    case Sd1a3 = 'sd_1_3';
    case Sd4a6 = 'sd_4_6';
    case Smp = 'smp';
    case Sma = 'sma';
    case Slb = 'slb';
    case Santri = 'santri';
    case PendidikanKhususLainnya = 'pendidikan_khusus_lainnya';
    case PendidikanInformal = 'pendidikan_informal';
    case IbuHamil = 'ibu_hamil';
    case IbuMenyusui = 'ibu_menyusui';
    case FoodSample = 'food_sample';
    case UjiOrganoleptik = 'uji_organoleptik';
    case PendidikDanTenaga = 'pendidik_tenaga_kependidikan';

    public function label(): string
    {
        return match ($this) {
            self::AnakBalita => 'Anak Balita',
            self::PaudTk => 'PAUD/TK/RA',
            self::Sd1a3 => 'SD/MI kelas 1-3',
            self::Sd4a6 => 'SD/MI kelas 4-6',
            self::Smp => 'SMP/MTS',
            self::Sma => 'SMA/MA/SMK',
            self::Slb => 'SLB',
            self::Santri => 'Santri',
            self::PendidikanKhususLainnya => 'Pendidikan khusus lainnya',
            self::PendidikanInformal => 'Pendidikan informal',
            self::IbuHamil => 'Ibu hamil',
            self::IbuMenyusui => 'Ibu menyusui',
            self::FoodSample => 'Food Sample (porsi)',
            self::UjiOrganoleptik => 'Uji Organoleptik (porsi)',
            self::PendidikDanTenaga => 'Pendidik dan Tenaga Kependidikan',
        };
    }

    public function kecil(): bool
    {
        return in_array($this, [self::AnakBalita, self::PaudTk, self::Sd1a3], true);
    }

    /** Baris porsi (bukan orang): sumber dari log produksi, bukan dari proporsi penerima. */
    public function porsiLangsung(): bool
    {
        return $this === self::FoodSample || $this === self::UjiOrganoleptik;
    }

    /**
     * Kelompok 30a dari klasifikasi penerima. null = belum terklasifikasi (tidak ditebak). Jenis satuan khusus
     * (SLB, pesantren, pendidikan khusus, informal) menang atas jenjang. ATS dipetakan ke Pendidikan informal
     * (tafsiran, perlu persetujuan; lihat laporan R07-2).
     */
    public static function dari(KelompokSasaran $kelompok, ?JenjangPenerima $jenjang, ?JenisSatuanPendidikan $satuan): ?self
    {
        return match ($kelompok) {
            KelompokSasaran::Balita => self::AnakBalita,
            KelompokSasaran::IbuHamil => self::IbuHamil,
            KelompokSasaran::IbuMenyusui => self::IbuMenyusui,
            KelompokSasaran::Pendidik, KelompokSasaran::TenagaKependidikan => self::PendidikDanTenaga,
            KelompokSasaran::Siswa, KelompokSasaran::Ats => self::dariPendidikan($jenjang, $satuan),
        };
    }

    private static function dariPendidikan(?JenjangPenerima $jenjang, ?JenisSatuanPendidikan $satuan): ?self
    {
        $khusus = match ($satuan) {
            JenisSatuanPendidikan::Slb => self::Slb,
            JenisSatuanPendidikan::Pesantren => self::Santri,
            JenisSatuanPendidikan::PendidikanKhusus => self::PendidikanKhususLainnya,
            JenisSatuanPendidikan::Informal => self::PendidikanInformal,
            default => null,
        };

        if ($khusus !== null) {
            return $khusus;
        }

        return match ($jenjang) {
            JenjangPenerima::PaudTk => self::PaudTk,
            JenjangPenerima::Sd1a3 => self::Sd1a3,
            JenjangPenerima::Sd4a6 => self::Sd4a6,
            JenjangPenerima::Smp => self::Smp,
            JenjangPenerima::Sma => self::Sma,
            JenjangPenerima::AtsKurang9, JenjangPenerima::Ats9a18 => self::PendidikanInformal,
            null => null,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
