<?php

namespace App\Enums;

/** Status satu pengajuan persetujuan pengeluaran (persetujuan_pengeluarans.status). Selain `diajukan` final. */
enum StatusPengajuanPengeluaran: string
{
    case Diajukan = 'diajukan';
    case Disetujui = 'disetujui';
    case Ditolak = 'ditolak';
    case Dibatalkan = 'dibatalkan';

    public function label(): string
    {
        return match ($this) {
            self::Diajukan => 'Menunggu Persetujuan',
            self::Disetujui => 'Disetujui',
            self::Ditolak => 'Ditolak',
            self::Dibatalkan => 'Dibatalkan',
        };
    }

    /** Keputusan yang boleh diambil investor. @return list<string> */
    public static function keputusan(): array
    {
        return [self::Disetujui->value, self::Ditolak->value];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
