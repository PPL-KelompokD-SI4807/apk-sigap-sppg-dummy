<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// PBI 6: peringatan stok minimum & kedaluwarsa, harian pagi (WIB).
Schedule::command('sigap:cek-stok')
    ->dailyAt((string) config('gudang.cek_stok_jam', '06:00'))
    ->timezone(config('app.timezone'))
    ->withoutOverlapping()
    ->onOneServer();

// PBI 2: deteksi keterlambatan perjalanan mobil (estimasi tiba terlewati), tiap 5 menit.
Schedule::command('sigap:cek-keterlambatan')
    ->everyFiveMinutes()
    ->timezone(config('operasional.timezone'))
    ->withoutOverlapping()
    ->onOneServer();

// PBI 16: rekap harian operasional semua SPPG aktif, 17:00 WIB.
Schedule::command('sigap:rekap-harian')
    ->dailyAt((string) config('operasional.rekap.jam_kirim', '17:00'))
    ->timezone(config('operasional.timezone'))
    ->withoutOverlapping()
    ->onOneServer();

// Proses bisnis proposal: bukti distribusi belum masuk (jam rencana tiba + 60 menit), tiap 10 menit.
Schedule::command('sigap:cek-bukti-distribusi')
    ->everyTenMinutes()
    ->timezone(config('operasional.timezone'))
    ->withoutOverlapping()
    ->onOneServer();

// NFR-06: hapus unggahan tertunda yang tidak pernah diikat ke data (berkas yatim), tiap jam.
Schedule::command('sigap:bersihkan-lampiran-tertunda')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

// NFR-03: update webhook Telegram yang tersimpan tetapi tidak sampai ke antrean, tiap 5 menit.
Schedule::command('sigap:proses-ulang-telegram')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// R-02: hapus token tombol Bot Telegram (bot_aksi) yang lebih tua dari 30 hari, harian dini hari.
Schedule::command('sigap:bersihkan-aksi-bot')
    ->dailyAt('03:30')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping()
    ->onOneServer();
