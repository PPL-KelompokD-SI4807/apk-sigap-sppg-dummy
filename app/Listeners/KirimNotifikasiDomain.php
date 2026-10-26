<?php

namespace App\Listeners;

use App\Services\Notification\DomainEventNotifications;
use App\Services\Notification\NotificationService;
use Throwable;

/**
 * Listener tunggal untuk semua event domain di DomainEventNotifications::EVENTS.
 * Didaftarkan eksplisit oleh SigapIntegrationServiceProvider (hanya kelas event yang ada);
 * parameter bertipe `object` sehingga tidak ikut auto-discovery (tidak terdaftar ganda).
 *
 * Kegagalan notifikasi TIDAK boleh menggagalkan alur domain: semua galat di-report lalu ditelan.
 */
class KirimNotifikasiDomain
{
    public function __construct(
        private readonly DomainEventNotifications $catalog,
        private readonly NotificationService $notifications,
    ) {}

    public function handle(object $event): void
    {
        try {
            $messages = $this->catalog->buildAll($event);
        } catch (Throwable $e) {
            report($e);

            return;
        }

        // Tiap varian dikirim terpisah: kegagalan satu varian tidak membatalkan varian lain.
        foreach ($messages as $message) {
            try {
                $this->notifications->send($message);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
