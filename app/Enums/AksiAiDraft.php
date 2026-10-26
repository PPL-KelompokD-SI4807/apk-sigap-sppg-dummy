<?php

namespace App\Enums;

enum AksiAiDraft: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';

    public function label(): string
    {
        return match ($this) {
            self::Create => 'Buat',
            self::Update => 'Ubah',
            self::Delete => 'Hapus',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
