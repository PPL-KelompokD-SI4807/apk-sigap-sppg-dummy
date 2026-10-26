<?php

namespace App\Enums;

/** Status verifikasi pembelian bahan (pembelian_bahans.status). `terverifikasi` bersifat final. */
enum StatusPembelianBahan: string
{
    case Diajukan = 'diajukan';
    case Terverifikasi = 'terverifikasi';
    case Ditolak = 'ditolak';

    public function label(): string
    {
        return match ($this) {
            self::Diajukan => 'Menunggu Verifikasi',
            self::Terverifikasi => 'Terverifikasi',
            self::Ditolak => 'Ditolak',
        };
    }

    /** Pembelian masih boleh diubah staf gudang. */
    public function bisaDiubah(): bool
    {
        return $this !== self::Terverifikasi;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
