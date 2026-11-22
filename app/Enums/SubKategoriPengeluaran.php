<?php

namespace App\Enums;

/** 17 baris baku Biaya Operasional Lampiran 30b-B (label disalin dari juknis BGN). */
enum SubKategoriPengeluaran: string
{
    case InsentifRelawan = 'insentif_relawan';
    case InsentifHarianPjSatuan = 'insentif_harian_pj_satuan';
    case InsentifKader = 'insentif_kader';
    case BpjsKetenagakerjaan = 'bpjs_ketenagakerjaan';
    case Listrik = 'listrik';
    case AirKemasanGalon = 'air_kemasan_galon';
    case AirPdam = 'air_pdam';
    case AirMinum = 'air_minum';
    case Gas = 'gas';
    case SewaKendaraan = 'sewa_kendaraan';
    case Bbm = 'bbm';
    case Pulsa = 'pulsa';
    case Internet = 'internet';
    case Atk = 'atk';
    case AlatKebersihan = 'alat_kebersihan';
    case Apd = 'apd';
    case LainLain = 'lain_lain';

    public function label(): string
    {
        return match ($this) {
            self::InsentifRelawan => 'Insentif Relawan',
            self::InsentifHarianPjSatuan => 'Insentif Harian Penanggung Jawab Satuan Pendidikan',
            self::InsentifKader => 'Insentif Kader',
            self::BpjsKetenagakerjaan => 'BPJS Ketenagakerjaan',
            self::Listrik => 'Listrik',
            self::AirKemasanGalon => 'Air Kemasan Galon',
            self::AirPdam => 'Air PDAM',
            self::AirMinum => 'Air Minum',
            self::Gas => 'Gas',
            self::SewaKendaraan => 'Sewa Kendaraan',
            self::Bbm => 'BBM',
            self::Pulsa => 'Pulsa',
            self::Internet => 'Internet',
            self::Atk => 'ATK',
            self::AlatKebersihan => 'Alat Kebersihan',
            self::Apd => 'APD',
            self::LainLain => 'Lain-lain',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
