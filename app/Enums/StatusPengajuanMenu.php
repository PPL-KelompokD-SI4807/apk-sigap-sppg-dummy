<?php

namespace App\Enums;

/** Status satu pengajuan menu (pengajuan_menus.status). Selain `diajukan` bersifat final. */
enum StatusPengajuanMenu: string
{
    case Diajukan = 'diajukan';
    case Disetujui = 'disetujui';
    case Ditolak = 'ditolak';
    case PerluRevisi = 'perlu_revisi';
    case Dibatalkan = 'dibatalkan';

    public function label(): string
    {
        return match ($this) {
            self::Diajukan => 'Menunggu Persetujuan',
            self::Disetujui => 'Disetujui',
            self::Ditolak => 'Ditolak',
            self::PerluRevisi => 'Perlu Revisi',
            self::Dibatalkan => 'Dibatalkan',
        };
    }

    /** Keputusan yang boleh diambil investor. @return list<string> */
    public static function keputusan(): array
    {
        return [self::Disetujui->value, self::Ditolak->value, self::PerluRevisi->value];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
