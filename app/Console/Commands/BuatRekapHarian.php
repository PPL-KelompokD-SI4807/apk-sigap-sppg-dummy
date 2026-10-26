<?php

namespace App\Console\Commands;

use App\Events\RekapHarianDibuat;
use App\Services\Rekap\DailySummaryService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class BuatRekapHarian extends Command
{
    protected $signature = 'sigap:rekap-harian
        {--tanggal= : Tanggal rekap YYYY-MM-DD (default hari ini, WIB)}
        {--sppg= : Batasi ke satu ID SPPG}';

    protected $description = 'Susun snapshot Laporan Rekapitulasi Harian untuk semua SPPG aktif dan pancarkan RekapHarianDibuat (terjadwal 17:00 WIB)';

    public function handle(DailySummaryService $service): int
    {
        $tanggal = $this->option('tanggal');

        if ($tanggal !== null && (! is_string($tanggal) || ! $this->tanggalSah($tanggal))) {
            $this->error('Format --tanggal harus YYYY-MM-DD yang sah.');

            return self::INVALID;
        }

        $sppg = $this->option('sppg');

        if ($sppg !== null && ! ctype_digit((string) $sppg)) {
            $this->error('--sppg harus berupa ID numerik.');

            return self::INVALID;
        }

        $hasil = $service->susunSemuaSppgAktif($tanggal, $sppg === null ? null : (int) $sppg);

        foreach ($hasil['berhasil'] as $rekap) {
            event(RekapHarianDibuat::dari($rekap));
        }

        $this->info('Rekap harian disusun: '.count($hasil['berhasil']).' SPPG, gagal: '.count($hasil['gagal']));

        return $hasil['gagal'] === [] ? self::SUCCESS : self::FAILURE;
    }

    private function tanggalSah(string $tanggal): bool
    {
        try {
            return Carbon::createFromFormat('Y-m-d', $tanggal) !== false
                && Carbon::createFromFormat('Y-m-d', $tanggal)->format('Y-m-d') === $tanggal;
        } catch (\Throwable) {
            return false;
        }
    }
}
