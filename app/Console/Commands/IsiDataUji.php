<?php

namespace App\Console\Commands;

use App\Services\DataUji\PenandaDataUji;
use App\Services\DataUji\PenghapusDataUji;
use App\Services\DataUji\PengisiDataUji;
use Illuminate\Console\Command;
use RuntimeException;

class IsiDataUji extends Command
{
    protected $signature = 'sigap:isi-data-uji
        {--confirm : Benar-benar menulis/menghapus (tanpa opsi ini hanya mode kering)}
        {--hapus : Hapus data uji (SPPG-UJI-01 dan seluruh turunannya) alih-alih mengisi}
        {--lepas-pengguna : Saat --hapus: kosongkan sppg_id akun yang tertaut ke SPPG uji (akun tidak dihapus)}
        {--izinkan-produksi : Izinkan menulis/menghapus saat APP_ENV=production (staging memakai production)}';

    protected $description = 'Isi atau hapus data uji fiktif satu SPPG "(Uji)" untuk staging (idempoten; mode kering tanpa --confirm)';

    public function handle(PengisiDataUji $pengisi, PenghapusDataUji $penghapus): int
    {
        $tulis = (bool) $this->option('confirm');

        if ($tulis && $this->laravel->environment('production') && ! $this->option('izinkan-produksi')) {
            $this->error('APP_ENV=production: penulisan/penghapusan data uji ditolak. Tambahkan --izinkan-produksi bila ini memang staging.');

            return self::FAILURE;
        }

        if (! $tulis) {
            $this->warn('MODE KERING: tidak ada yang ditulis atau dihapus. Tambahkan --confirm untuk melaksanakan.');
        }

        return $this->option('hapus') ? $this->hapus($penghapus, $tulis) : $this->isi($pengisi, $tulis);
    }

    private function isi(PengisiDataUji $pengisi, bool $tulis): int
    {
        if (PenandaDataUji::sppg() !== null) {
            $this->info(PenandaDataUji::KODE_SPPG.' sudah ada; dilewati (tidak ada perubahan).');

            return self::SUCCESS;
        }

        $baris = $pengisi->isi($tulis);

        $this->tabel($baris);
        $this->info($tulis
            ? 'Data uji dibuat ('.array_sum($baris).' baris). Tidak ada pengguna, ID Telegram, NIK, atau berkas yang dibuat.'
            : 'Ringkasan di atas adalah rencana ('.array_sum($baris).' baris); belum ada yang ditulis.');

        return self::SUCCESS;
    }

    private function hapus(PenghapusDataUji $penghapus, bool $tulis): int
    {
        $sppg = PenandaDataUji::sppg();

        if ($sppg === null) {
            $this->info(PenandaDataUji::KODE_SPPG.' tidak ada; tidak ada yang dihapus.');

            return self::SUCCESS;
        }

        try {
            $rencana = $penghapus->rencana($sppg);

            if (! $tulis) {
                $this->tabel($rencana['baris']);
                $this->line(sprintf('Pengguna tertaut: %d; tautan investor: %d (akun tidak pernah dihapus; lihat --lepas-pengguna).', $rencana['pengguna'], $rencana['investor']));
                $this->info('Rencana penghapusan di atas belum dilaksanakan.');

                return self::SUCCESS;
            }

            $terhapus = $penghapus->hapus($sppg, (bool) $this->option('lepas-pengguna'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->tabel($terhapus);
        $this->info('Data uji dihapus.');

        return self::SUCCESS;
    }

    /** @param  array<string, int>  $baris */
    private function tabel(array $baris): void
    {
        $this->table(['Tabel', 'Baris'], collect($baris)->filter()->map(fn (int $n, string $t) => [$t, $n])->values()->all());
    }
}
