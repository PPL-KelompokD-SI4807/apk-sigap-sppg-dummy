<?php

namespace App\Enums;

/**
 * Siklus hidup AiDraft. Terbuka (masih dapat dijalankan/disimpan): pending, menunggu_konfirmasi_ulang.
 * Final: confirmed (disimpan lewat Mini App ATAU dijalankan lewat tombol Bot), discarded (dibuang di Mini App),
 * cancelled (Batal di chat), expired (lewat TTL), gagal (eksekusi Bot ditolak/gagal; draf tidak dipakai lagi).
 */
enum StatusAiDraft: string
{
    case Pending = 'pending';
    case MenungguKonfirmasiUlang = 'menunggu_konfirmasi_ulang';
    case Confirmed = 'confirmed';
    case Discarded = 'discarded';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Gagal = 'gagal';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu Konfirmasi',
            self::MenungguKonfirmasiUlang => 'Menunggu Konfirmasi Ulang',
            self::Confirmed => 'Dikonfirmasi',
            self::Discarded => 'Dibuang',
            self::Cancelled => 'Dibatalkan',
            self::Expired => 'Kedaluwarsa',
            self::Gagal => 'Gagal',
        };
    }

    public function terbuka(): bool
    {
        return in_array($this, self::terbukaSemua(), true);
    }

    /** @return list<self> */
    public static function terbukaSemua(): array
    {
        return [self::Pending, self::MenungguKonfirmasiUlang];
    }

    /** @return list<string> */
    public static function nilaiTerbuka(): array
    {
        return array_map(fn (self $s) => $s->value, self::terbukaSemua());
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
