<?php

namespace App\Services\Notification;

use App\Enums\StatusKirim;

/**
 * Status kirim pesan ke PIC titik layanan sebagaimana ditampilkan di detail jadwal distribusi
 * (objek `notifikasi_pic`): menunggu | terkirim | gagal | dialihkan_ke_kepala.
 */
final class StatusKirimPic
{
    public const MENUNGGU = 'menunggu';

    public const TERKIRIM = 'terkirim';

    public const GAGAL = 'gagal';

    public const DIALIHKAN_KE_KEPALA = 'dialihkan_ke_kepala';

    /** Pemetaan dari status_kirim notifikasi perjalanan (pesan "berangkat"). */
    public static function dariPerjalanan(StatusKirim $status, bool $dialihkanKeKepala): string
    {
        if ($dialihkanKeKepala) {
            return self::DIALIHKAN_KE_KEPALA;
        }

        return match ($status) {
            StatusKirim::Terkirim => self::TERKIRIM,
            StatusKirim::Menunggu => self::MENUNGGU,
            StatusKirim::Gagal, StatusKirim::TidakTerkirim => self::GAGAL,
        };
    }
}
