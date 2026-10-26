<?php

namespace App\Enums;

/** Status persetujuan investor pada menu (menus.status_persetujuan). Hanya `disetujui` dapat dijadwalkan. */
enum StatusPersetujuanMenu: string
{
    case Draf = 'draf';
    case Diajukan = 'diajukan';
    case Disetujui = 'disetujui';
    case Ditolak = 'ditolak';
    case PerluRevisi = 'perlu_revisi';

    public function label(): string
    {
        return match ($this) {
            self::Draf => 'Draf',
            self::Diajukan => 'Menunggu Persetujuan',
            self::Disetujui => 'Disetujui',
            self::Ditolak => 'Ditolak',
            self::PerluRevisi => 'Perlu Revisi',
        };
    }

    /** Status yang boleh diajukan (ulang). */
    public function bisaDiajukan(): bool
    {
        return in_array($this, [self::Draf, self::Ditolak, self::PerluRevisi], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
