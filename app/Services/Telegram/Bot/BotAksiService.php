<?php

namespace App\Services\Telegram\Bot;

use App\Models\BotAksi;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Siklus hidup tombol aksi Bot (R-02): terbit, cari (MAC + baris), konsumsi atomik (sekali pakai), tutup.
 *
 * Konsumsi memakai UPDATE bersyarat (`status='aktif' AND expires_at>now`) dan harus mempengaruhi tepat satu
 * baris; tekan ganda/replay/update paralel gagal di UPDATE ini sebelum service bisnis dipanggil.
 */
class BotAksiService
{
    /**
     * @param  array{sppg_id?: int|null, subjek_tipe?: string|null, subjek_id?: int|null, subjek_versi?: int|null}  $opsi
     */
    public function terbit(User $user, int $chatId, string $jenis, array $opsi = [], ?string $grup = null): BotAksi
    {
        for ($i = 0; $i < 5; $i++) {
            try {
                $aksi = new BotAksi;
                $aksi->forceFill([
                    'kode' => BotKodeCodec::kodeBaru(),
                    'grup' => $grup,
                    'user_id' => $user->getKey(),
                    'telegram_id' => (int) $user->telegram_id,
                    'chat_id' => $chatId,
                    'sppg_id' => $opsi['sppg_id'] ?? null,
                    'jenis' => $jenis,
                    'subjek_tipe' => $opsi['subjek_tipe'] ?? null,
                    'subjek_id' => $opsi['subjek_id'] ?? null,
                    'subjek_versi' => $opsi['subjek_versi'] ?? null,
                    'status' => BotAksi::STATUS_AKTIF,
                    'expires_at' => now()->addMinutes(max(1, (int) config('telegram.callback_ttl_menit', 10))),
                ])->save();

                $this->batasiAktif((int) $user->getKey(), (int) $aksi->getKey());

                return $aksi;
            } catch (QueryException $e) {
                // Tabrakan kode acak (sangat jarang): ulangi dengan kode baru. Dikenali dari SQLSTATE 23000.
                if ($i === 4 || ($e->errorInfo[0] ?? null) !== '23000') {
                    throw $e;
                }
            }
        }

        throw new LogicException('Tidak dapat menerbitkan kode aksi.');
    }

    public static function grupBaru(): string
    {
        return BotKodeCodec::kodeBaru();
    }

    public function callbackData(BotAksi $aksi): string
    {
        return BotKodeCodec::data($aksi->kode, (int) $aksi->telegram_id);
    }

    /** @return array{text: string, callback_data: string} */
    public function tombol(string $label, BotAksi $aksi): array
    {
        return ['text' => mb_substr($label, 0, 64), 'callback_data' => $this->callbackData($aksi)];
    }

    /** Baris untuk callback_data ini bila MAC cocok untuk $fromId dan baris ada; selain itu null. */
    public function temukan(mixed $data, int $fromId): ?BotAksi
    {
        $kode = BotKodeCodec::urai($data, $fromId);

        return $kode === null ? null : BotAksi::query()->where('kode', $kode)->first();
    }

    /**
     * Apakah baris masih berlaku dan terikat ke pengirim ini: aktif, belum kedaluwarsa, from.id == telegram_id,
     * chat.id == chat_id, user_id == pengguna yang ditemukan dari from.id sekarang.
     */
    public function berlaku(BotAksi $aksi, int $fromId, int $chatId, ?User $user): bool
    {
        return $aksi->status === BotAksi::STATUS_AKTIF
            && $aksi->expires_at->isFuture()
            && (int) $aksi->telegram_id === $fromId
            && (int) $aksi->chat_id === $chatId
            && $user !== null
            && (int) $aksi->user_id === (int) $user->getKey();
    }

    /**
     * Konsumsi atomik. $ke = dipakai (default) atau menunggu_catatan (jendela balasan alasan penolakan dibuka).
     * Hanya satu pemanggil yang mendapat true untuk baris yang sama, dan hanya SATU token per layar (grup) yang
     * pernah dikonsumsi: baris satu grup dikunci (lockForUpdate, urut id) dalam satu transaksi, jadi Setujui dan
     * Tolak dari layar yang sama tidak mungkin sama-sama lolos.
     */
    public function konsumsi(BotAksi $aksi, string $ke = BotAksi::STATUS_DIPAKAI): bool
    {
        $nilai = ['status' => $ke, 'used_at' => now()];

        if ($ke === BotAksi::STATUS_MENUNGGU_CATATAN) {
            $nilai['catatan_expires_at'] = now()->addMinutes(max(1, (int) config('telegram.catatan_ttl_menit', 10)));
        }

        $berhasil = DB::transaction(function () use ($aksi, $nilai): bool {
            if ($aksi->grup === null) {
                return BotAksi::query()
                    ->whereKey($aksi->getKey())
                    ->where('status', BotAksi::STATUS_AKTIF)
                    ->where('expires_at', '>', now())
                    ->update($nilai) === 1;
            }

            $baris = BotAksi::query()->where('grup', $aksi->grup)->orderBy('id')->lockForUpdate()->get();
            $saya = $baris->firstWhere('id', $aksi->getKey());

            if ($saya === null
                || $saya->status !== BotAksi::STATUS_AKTIF
                || ! $saya->expires_at->isFuture()
                || $baris->contains(fn (BotAksi $r) => $r->getKey() !== $saya->getKey()
                    && in_array($r->status, [BotAksi::STATUS_DIPAKAI, BotAksi::STATUS_MENUNGGU_CATATAN, BotAksi::STATUS_SELESAI], true))) {
                return false;
            }

            BotAksi::query()->whereKey($saya->getKey())->update($nilai);
            BotAksi::query()
                ->where('grup', $aksi->grup)
                ->whereKeyNot($saya->getKey())
                ->where('status', BotAksi::STATUS_AKTIF)
                ->update(['status' => BotAksi::STATUS_KEDALUWARSA]);

            return true;
        });

        if ($berhasil) {
            $aksi->forceFill($nilai)->syncOriginal();

            // Satu jendela catatan per pengguna: yang lama dibatalkan.
            if ($ke === BotAksi::STATUS_MENUNGGU_CATATAN) {
                $this->batalkanCatatan((int) $aksi->telegram_id, (int) $aksi->getKey());
            }
        }

        return $berhasil;
    }

    /** Klaim atomik balasan catatan: menunggu_catatan -> dipakai selama jendela masih terbuka. */
    public function klaimCatatan(BotAksi $aksi): bool
    {
        return BotAksi::query()
            ->whereKey($aksi->getKey())
            ->where('status', BotAksi::STATUS_MENUNGGU_CATATAN)
            ->where('catatan_expires_at', '>', now())
            ->update(['status' => BotAksi::STATUS_DIPAKAI]) === 1;
    }

    public function selesai(BotAksi $aksi, string $hasil): void
    {
        BotAksi::query()->whereKey($aksi->getKey())->update(['status' => BotAksi::STATUS_SELESAI, 'hasil' => mb_substr($hasil, 0, 40)]);
    }

    public function simpanPrompt(BotAksi $aksi, int $messageId): void
    {
        BotAksi::query()->whereKey($aksi->getKey())->update(['prompt_message_id' => $messageId]);
    }

    /** Token saudara (satu layar) yang masih aktif ikut kedaluwarsa setelah satu tombol dipakai. */
    public function kedaluwarsakanSaudara(BotAksi $aksi): void
    {
        if ($aksi->grup === null) {
            return;
        }

        BotAksi::query()
            ->where('grup', $aksi->grup)
            ->whereKeyNot($aksi->getKey())
            ->where('status', BotAksi::STATUS_AKTIF)
            ->update(['status' => BotAksi::STATUS_KEDALUWARSA]);
    }

    /** Batalkan jendela catatan yang terbuka (token baru, /batal, atau sesi dicabut). */
    public function batalkanCatatan(int $telegramId, ?int $kecualiId = null): int
    {
        return BotAksi::query()
            ->where('telegram_id', $telegramId)
            ->where('status', BotAksi::STATUS_MENUNGGU_CATATAN)
            ->when($kecualiId !== null, fn ($q) => $q->whereKeyNot($kecualiId))
            ->update(['status' => BotAksi::STATUS_KEDALUWARSA]);
    }

    /** Baris tolak untuk balasan ke pesan prompt $messageId (status apa pun), atau null. */
    public function untukPrompt(int $telegramId, int $chatId, int $messageId): ?BotAksi
    {
        return BotAksi::query()
            ->where('telegram_id', $telegramId)
            ->where('chat_id', $chatId)
            ->where('prompt_message_id', $messageId)
            ->where('jenis', BotAksi::JENIS_TOLAK)
            ->latest('id')
            ->first();
    }

    /** Hapus baris lebih tua dari $hari hari. */
    public function bersihkan(int $hari): int
    {
        return BotAksi::query()->where('created_at', '<', now()->subDays(max(1, $hari)))->delete();
    }

    private function batasiAktif(int $userId, int $barisBaruId): void
    {
        $maks = max(1, (int) config('telegram.aksi_aktif_maks', 50));
        $jumlah = BotAksi::query()->where('user_id', $userId)->where('status', BotAksi::STATUS_AKTIF)->count();

        if ($jumlah <= $maks) {
            return;
        }

        $buang = BotAksi::query()
            ->where('user_id', $userId)
            ->where('status', BotAksi::STATUS_AKTIF)
            ->whereKeyNot($barisBaruId)
            ->orderBy('id')
            ->limit($jumlah - $maks)
            ->pluck('id');

        BotAksi::query()->whereKey($buang->all())->update(['status' => BotAksi::STATUS_KEDALUWARSA]);
    }
}
