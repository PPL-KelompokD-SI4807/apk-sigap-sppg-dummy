<?php

namespace App\Enums;

/**
 * Laporan Lampiran 30a-30d juknis BGN (Kepka 401.1/2025). Enam bentuk data; `jenisDokumen()` menentukan baris
 * pencatat/penomoran (30d bagian A, B, C satu laporan bulanan dengan satu nomor).
 *
 * Investor hanya agregat (Q-4 R-07): 30a, 30c, 30d-A, 30d-B. 30b dan 30d-C menyilangkan titik layanan x kelompok x
 * jenis kelamin sehingga bagi investor ditolak (403), bukan disamarkan.
 */
enum JenisLaporanBgn: string
{
    case Porsi = 'porsi';                              // 30a
    case Harian = 'harian';                            // 30b
    case DuaMingguan = 'dua_mingguan';                 // 30c
    case BulananRingkasan = 'bulanan_ringkasan';       // 30d-A
    case BulananAnggaran = 'bulanan_anggaran';         // 30d-B
    case BulananPenerima = 'bulanan_penerima';         // 30d-C

    public function lampiran(): string
    {
        return match ($this) {
            self::Porsi => '30a',
            self::Harian => '30b',
            self::DuaMingguan => '30c',
            self::BulananRingkasan => '30d-A',
            self::BulananAnggaran => '30d-B',
            self::BulananPenerima => '30d-C',
        };
    }

    public function judul(): string
    {
        return match ($this) {
            self::Porsi => 'Rekapitulasi Perhitungan Porsi MBG di SPPG',
            self::Harian => 'Laporan Harian Penggunaan Dana',
            self::DuaMingguan => 'Laporan 2 (Dua) Mingguan Penggunaan Dana Program MBG TA',
            self::BulananRingkasan => 'Laporan Bulanan Penggunaan Dana Program MBG TA (Summary)',
            self::BulananAnggaran => 'Laporan Bulanan Penggunaan Dana Program MBG TA (Anggaran)',
            self::BulananPenerima => 'Laporan Bulanan Penggunaan Dana Program MBG TA (Penerima Manfaat)',
        };
    }

    /** Bentuk agregat saja (boleh untuk investor). */
    public function agregat(): bool
    {
        return $this !== self::Harian && $this !== self::BulananPenerima;
    }

    /** Jenis pencatat (`laporan_bgns.jenis`): 30d A/B/C berbagi satu baris dan satu nomor. */
    public function jenisDokumen(): string
    {
        return match ($this) {
            self::BulananRingkasan, self::BulananAnggaran, self::BulananPenerima => 'bulanan',
            default => $this->value,
        };
    }

    /** Memuat `Nomor: xx/LPA/x/tahun` (30a dan 30d-C tidak bernomor menurut juknis). */
    public function bernomor(): bool
    {
        return $this !== self::Porsi && $this !== self::BulananPenerima;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
