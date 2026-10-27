<?php

namespace App\Services\Telegram\Bot\Layar;

use App\Models\User;
use App\Services\Telegram\Bot\BotLayar;
use App\Services\Telegram\Bot\BotLayarService;

/**
 * Layar daftar generik (R02-B1) dari satu atau lebih SeksiDaftar: tiap seksi = satu tool baca katalog lewat
 * BotLayarService (RBAC, Policy, SPPG, penyamaran) dengan whitelist kolom. Seksi tunggal -> paginasi 5 baris
 * (maks. 4 halaman); beberapa seksi -> 5 baris pertama tiap seksi. Seksi yang tool-nya tidak tersedia bagi peran
 * ini (mis. jadwal produksi untuk relawan distribusi) dilewati; tanpa seksi tersedia -> layar tidak dapat dibuka.
 */
final class LayarDaftar extends LayarModulDasar
{
    /**
     * @param  list<SeksiDaftar>  $seksi
     */
    public function __construct(
        private readonly BotLayarService $layanan,
        private readonly string $id,
        private readonly string $modul,
        private readonly string $judul,
        private readonly array $seksi,
        private readonly ?string $rute = null,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    /** @return list<SeksiDaftar> */
    public function seksi(): array
    {
        return $this->seksi;
    }

    public function modul(): ?string
    {
        return $this->modul;
    }

    protected function judul(): string
    {
        return $this->judul;
    }

    protected function rute(): ?string
    {
        return $this->rute;
    }

    /** Minimal satu seksi tersedia (RBAC + unit + Policy viewAny milik tool) bagi pengguna saat ini. */
    public function izinkan(User $user, ?int $sppgId): bool
    {
        foreach ($this->seksi as $seksi) {
            if ($this->layanan->tersedia($user, $seksi->sumber)) {
                return true;
            }
        }

        return false;
    }

    public function render(User $user, ?int $sppgId, KonteksLayar $konteks): BotLayar
    {
        $berhalaman = count($this->seksi) === 1;
        $halaman = $berhalaman ? $konteks->halaman : 0;
        $isi = [];
        $adaLagi = false;

        foreach ($this->seksi as $seksi) {
            $hasil = $this->layanan->daftar($user, $seksi->sumber, $seksi->argumenUntuk($user), $seksi->kolom, $halaman);

            if ($hasil === null) {
                continue;
            }

            if (! $berhalaman) {
                $isi[] = "\n<b>".self::e($seksi->judul).'</b>';
            }

            if ($hasil['baris'] === []) {
                $isi[] = self::e($seksi->kosong ?? 'Tidak ada data.');
            }

            foreach ($hasil['baris'] as $baris) {
                $isi[] = '- '.self::e(($seksi->format)($baris));
            }

            if ($hasil['ada_lagi'] && ! $berhalaman) {
                $isi[] = '<i>Masih ada data lain. Lihat di Mini App.</i>';
            }

            $adaLagi = $adaLagi || $hasil['ada_lagi'];
        }

        if ($isi === []) {
            $isi[] = 'Tidak ada data yang dapat ditampilkan untuk peran Anda.';
        }

        return $this->susun($user, $sppgId, $konteks, $isi, $berhalaman, $berhalaman && $adaLagi);
    }
}
