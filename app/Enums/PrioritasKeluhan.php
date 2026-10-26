<?php

namespace App\Enums;

enum PrioritasKeluhan: string
{
    case Rendah = 'rendah';
    case Sedang = 'sedang';
    case Tinggi = 'tinggi';
    case Kritis = 'kritis';

    public function label(): string
    {
        return match ($this) {
            self::Rendah => 'Rendah',
            self::Sedang => 'Sedang',
            self::Tinggi => 'Tinggi',
            self::Kritis => 'Kritis',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function dariKategori(KategoriKeluhan $kategori): self
    {
        return match ($kategori) {
            KategoriKeluhan::MakananBasi, KategoriKeluhan::GejalaSakit => self::Kritis,
            KategoriKeluhan::PorsiKurang, KategoriKeluhan::Terlambat => self::Sedang,
            default => self::Rendah,
        };
    }
}
