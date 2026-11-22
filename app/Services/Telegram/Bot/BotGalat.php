<?php

namespace App\Services\Telegram\Bot;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pencatat galat aman untuk jalur Bot yang membawa teks pengguna (mis. alasan penolakan).
 * Pesan exception (mis. QueryException memuat SQL + binding) TIDAK dicatat: hanya kelas, kode, dan lokasi.
 */
final class BotGalat
{
    public static function catat(Throwable $e, string $konteks): void
    {
        Log::error('bot.galat', [
            'konteks' => $konteks,
            'kelas' => $e::class,
            'kode' => (string) $e->getCode(),
            'berkas' => basename($e->getFile()),
            'baris' => $e->getLine(),
        ]);
    }
}
