<?php

namespace App\Enums;

/** Alur: baru -> diproses -> selesai | ditolak (keduanya final, catatan tindak lanjut wajib). */
enum StatusKeluhan: string
{
    case Baru = 'baru';
    case Diproses = 'diproses';
    case Selesai = 'selesai';
    case Ditolak = 'ditolak';

    public function label(): string
    {
        return match ($this) {
            self::Baru => 'Baru',
            self::Diproses => 'Diproses',
            self::Selesai => 'Selesai',
            self::Ditolak => 'Ditolak',
        };
    }

    /** @return list<self> */
    public function transisiValid(): array
    {
        return match ($this) {
            self::Baru => [self::Diproses],
            self::Diproses => [self::Selesai, self::Ditolak],
            self::Selesai, self::Ditolak => [],
        };
    }

    public function bolehKe(self $tujuan): bool
    {
        return in_array($tujuan, $this->transisiValid(), true);
    }

    /** Status final (tidak dapat berubah lagi). */
    public function final(): bool
    {
        return $this === self::Selesai || $this === self::Ditolak;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return list<string> */
    public static function terbukaValues(): array
    {
        return [self::Baru->value, self::Diproses->value];
    }
}
