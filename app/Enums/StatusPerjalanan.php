<?php

namespace App\Enums;

/** Siklus perjalanan mobil: berangkat -> dalam_perjalanan -> tiba / dibatalkan. */
enum StatusPerjalanan: string
{
    case Berangkat = 'berangkat';
    case DalamPerjalanan = 'dalam_perjalanan';
    case Tiba = 'tiba';
    case Dibatalkan = 'dibatalkan';

    public function label(): string
    {
        return match ($this) {
            self::Berangkat => 'Berangkat',
            self::DalamPerjalanan => 'Dalam Perjalanan',
            self::Tiba => 'Tiba',
            self::Dibatalkan => 'Dibatalkan',
        };
    }

    /** @return list<self> */
    public function transisiValid(): array
    {
        return match ($this) {
            self::Berangkat => [self::DalamPerjalanan, self::Dibatalkan],
            self::DalamPerjalanan => [self::Tiba, self::Dibatalkan],
            self::Tiba, self::Dibatalkan => [],
        };
    }

    public function bolehKe(self $tujuan): bool
    {
        return in_array($tujuan, $this->transisiValid(), true);
    }

    /** Perjalanan masih berjalan (belum tiba/batal). */
    public function aktif(): bool
    {
        return $this === self::Berangkat || $this === self::DalamPerjalanan;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return list<string> */
    public static function aktifValues(): array
    {
        return [self::Berangkat->value, self::DalamPerjalanan->value];
    }
}
