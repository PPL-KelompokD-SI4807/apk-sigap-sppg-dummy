<?php

namespace App\Services\Notification;

use App\Enums\Role;
use App\Jobs\SendTelegramNotification;
use App\Models\User;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pengiriman notifikasi bot terpusat (FR-10, FR-12, FR-13, NFR-03).
 *
 * - Penerima: pengguna AKTIF (tidak terhapus) ber-telegram_id dengan peran sesuai di SPPG event,
 *   ditambah chat id mentah (PIC titik layanan). Admin hanya sebagai penerima cadangan
 *   (fallbackToAdmin) bila tidak ada penerima lain.
 * - Dedup per (dedupKey, chat) selama DEDUP_HOURS jam: event ganda tidak menggandakan pesan.
 * - Pengiriman lewat job SendTelegramNotification (antrean, retry + backoff, setelah commit).
 * - Tombol web_app Mini App hanya untuk pengguna terdaftar (bukan PIC).
 */
class NotificationService
{
    public const DEDUP_HOURS = 12;

    public function __construct(
        private readonly TelegramBotService $bot,
        private readonly DeliveryTracker $tracker,
    ) {}

    /**
     * @return list<array{chat_id: int, user_id: int|null}> penerima yang dijadwalkan
     */
    public function send(NotificationMessage $message): array
    {
        if (! $this->bot->isConfigured()) {
            Log::info('notifikasi.bot_belum_dikonfigurasi', ['jenis' => $message->type]);

            return [];
        }

        $recipients = $this->resolveRecipients($message);

        if ($recipients === []) {
            $this->tracker->noRecipients($message->track);
            Log::info('notifikasi.tanpa_penerima', ['jenis' => $message->type, 'sppg_id' => $message->sppgId]);

            return [];
        }

        $button = $message->buttonLabel !== null
            ? TelegramBotService::webAppButton($message->buttonLabel, $this->bot->miniAppUrl([], $message->buttonPath))
            : null;

        $scheduled = [];

        foreach ($recipients as $chatId => $userId) {
            if (! Cache::add('notif:'.hash('sha256', $message->dedupKey.'|'.$chatId), 1, now()->addHours(self::DEDUP_HOURS))) {
                continue;
            }

            SendTelegramNotification::dispatch(
                chatId: $chatId,
                html: $message->html,
                replyMarkup: $userId !== null ? TelegramBotService::inlineKeyboard([$button]) : null,
                userId: $userId,
                type: $message->type,
                track: $message->track,
            )->afterCommit();

            $scheduled[] = ['chat_id' => $chatId, 'user_id' => $userId];
        }

        return $scheduled;
    }

    /**
     * @return array<int, int|null> chat_id => user_id (null = chat mentah, mis. PIC)
     */
    public function resolveRecipients(NotificationMessage $message): array
    {
        $recipients = [];

        foreach ($message->chatIds as $chatId) {
            if (is_int($chatId) && $chatId !== 0) {
                $recipients[$chatId] = null;
            }
        }

        if ($message->roles !== [] && $message->sppgId !== null) {
            foreach ($this->usersWithRoles($message->roles, $message->sppgId) as $user) {
                $recipients[(int) $user->telegram_id] = (int) $user->getKey();
            }
        }

        if ($recipients === [] && $message->fallbackToAdmin) {
            foreach ($this->usersWithRoles([Role::Admin], null) as $user) {
                $recipients[(int) $user->telegram_id] = (int) $user->getKey();
            }
        }

        return $recipients;
    }

    /**
     * @param  list<Role>  $roles
     * @return iterable<User>
     */
    private function usersWithRoles(array $roles, ?int $sppgId): iterable
    {
        $nilai = array_map(fn (Role $r) => $r->value, $roles);
        $query = User::query()->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereNotNull('telegram_id')
            ->orderBy('id')
            ->limit(200);

        if ($sppgId === null) {
            $query->whereIn('role', $nilai)->whereNull('sppg_id');
        } else {
            $investor = Role::Investor->value;
            $lain = array_values(array_diff($nilai, [$investor]));

            // Peran satu-SPPG lewat users.sppg_id; investor (sppg_id null) lewat pivot investor_sppg.
            $query->where(function ($q) use ($lain, $nilai, $investor, $sppgId): void {
                if ($lain !== []) {
                    $q->orWhere(fn ($w) => $w->whereIn('role', $lain)->where('sppg_id', $sppgId));
                }

                if (in_array($investor, $nilai, true)) {
                    $q->orWhere(fn ($w) => $w->where('role', $investor)->whereIn(
                        'id',
                        // Sejalan SppgAktif::milik(): SPPG yang sudah dihapus lunak tidak dihitung.
                        DB::table('investor_sppg')
                            ->join('sppgs', 'sppgs.id', '=', 'investor_sppg.sppg_id')
                            ->where('investor_sppg.sppg_id', $sppgId)
                            ->whereNull('sppgs.deleted_at')
                            ->select('investor_sppg.user_id'),
                    ));
                }
            });
        }

        return $query->get(['id', 'telegram_id', 'role', 'sppg_id', 'is_active']);
    }
}
