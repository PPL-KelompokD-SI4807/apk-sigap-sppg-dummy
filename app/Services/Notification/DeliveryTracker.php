<?php

namespace App\Services\Notification;

use App\Enums\StatusKirim;
use App\Models\NotifikasiPerjalanan;
use App\Models\RekapHarian;

/**
 * Mencatat hasil kirim ke record domain yang punya kolom status_kirim:
 * - notifikasi_perjalanan: status_kirim/waktu_kirim/error (FR-12; dialihkan ke Kepala tetap "tidak_terkirim").
 * - rekap_harian: status_kirim/dikirim_pada (FR-13; terkirim bila minimal satu penerima menerima).
 */
class DeliveryTracker
{
    public const PERJALANAN = 'notifikasi_perjalanan';

    public const REKAP = 'rekap_harian';

    /** @param array{type: string, id: int}|null $track */
    public function sent(?array $track): void
    {
        match ($track['type'] ?? null) {
            self::PERJALANAN => NotifikasiPerjalanan::query()->withoutGlobalScopes()->whereKey($track['id'])
                ->where('dialihkan_ke_kepala', false)
                ->update(['status_kirim' => StatusKirim::Terkirim->value, 'waktu_kirim' => now(), 'error' => null]),
            self::REKAP => RekapHarian::query()->withoutGlobalScopes()->whereKey($track['id'])
                ->where('status_kirim', '!=', StatusKirim::Terkirim->value)
                ->update(['status_kirim' => StatusKirim::Terkirim->value, 'dikirim_pada' => now()]),
            default => null,
        };
    }

    /** @param array{type: string, id: int}|null $track */
    public function failed(?array $track, string $reason): void
    {
        match ($track['type'] ?? null) {
            self::PERJALANAN => NotifikasiPerjalanan::query()->withoutGlobalScopes()->whereKey($track['id'])
                ->where('dialihkan_ke_kepala', false)
                ->where('status_kirim', '!=', StatusKirim::Terkirim->value)
                ->update(['status_kirim' => StatusKirim::Gagal->value, 'error' => mb_substr($reason, 0, 255)]),
            self::REKAP => RekapHarian::query()->withoutGlobalScopes()->whereKey($track['id'])
                ->where('status_kirim', StatusKirim::Menunggu->value)
                ->update(['status_kirim' => StatusKirim::Gagal->value]),
            default => null,
        };
    }

    /** @param array{type: string, id: int}|null $track */
    public function noRecipients(?array $track): void
    {
        match ($track['type'] ?? null) {
            self::PERJALANAN => NotifikasiPerjalanan::query()->withoutGlobalScopes()->whereKey($track['id'])
                ->where('status_kirim', StatusKirim::Menunggu->value)
                ->update(['status_kirim' => StatusKirim::TidakTerkirim->value, 'error' => 'Tidak ada penerima Telegram.']),
            self::REKAP => RekapHarian::query()->withoutGlobalScopes()->whereKey($track['id'])
                ->where('status_kirim', StatusKirim::Menunggu->value)
                ->update(['status_kirim' => StatusKirim::TidakTerkirim->value]),
            default => null,
        };
    }
}
