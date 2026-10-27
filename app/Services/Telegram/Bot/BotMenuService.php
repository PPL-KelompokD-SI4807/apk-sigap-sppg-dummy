<?php

namespace App\Services\Telegram\Bot;

use App\Models\BotAksi;
use App\Models\User;
use App\Services\Telegram\TelegramBotService;
use App\Support\SppgAktif;

/**
 * Menu tombol Bot per peran (R-02). Hanya dirender untuk pengguna dengan sesi login penuh; pemanggil
 * (TelegramUpdateHandler) wajib sudah memeriksa SesiService::punyaSesiPenuh().
 *
 * Pintasan layar = tombol web_app (tanpa kode/kedaluwarsa; Mini App tetap menuntut login + Policy).
 * Satu-satunya tombol callback adalah "Menunggu keputusan" untuk pengguna yang berhak memutuskan (investor).
 */
class BotMenuService
{
    public function __construct(
        private readonly TelegramBotService $bot,
        private readonly BotAksiService $aksi,
        private readonly BotKeputusanService $keputusan,
    ) {}

    public function layar(User $user, int $chatId): BotLayar
    {
        return new BotLayar(
            "<b>Menu SiGAP</b>\nPilih pintasan layar di bawah. Tombol hanya membuka Mini App; masuk dan hak akses tetap diperiksa di sana.",
            $this->keyboard($user, $chatId),
        );
    }

    /** @return array{inline_keyboard: list<list<array<string, mixed>>>}|null */
    public function keyboard(User $user, int $chatId): ?array
    {
        $baris = [];
        $investorSppg = $this->petunjukSppg($user);
        $sel = [];

        foreach (BotMenuCatalog::untuk($user) as $butir) {
            $query = ['r' => $butir['rute']] + ($investorSppg !== null ? ['sppg' => $investorSppg] : []);
            $tombol = TelegramBotService::webAppButton($butir['label'], $this->bot->miniAppUrl($query, $butir['rute']));

            if ($tombol === null) {
                continue;
            }

            $sel[] = $tombol;

            if (count($sel) === 2) {
                $baris[] = $sel;
                $sel = [];
            }
        }

        if ($sel !== []) {
            $baris[] = $sel;
        }

        if ($this->keputusan->bisaMemutuskan($user)) {
            $aksi = $this->aksi->terbit($user, $chatId, BotAksi::JENIS_DAFTAR);
            $baris[] = [$this->aksi->tombol('Menunggu keputusan', $aksi)];
        }

        $buka = TelegramBotService::webAppButton('Buka SiGAP', $this->bot->miniAppUrl());

        if ($buka !== null) {
            $baris[] = [$buka];
        }

        return $baris === [] ? null : ['inline_keyboard' => $baris];
    }

    /** Investor satu SPPG: petunjuk ?sppg= (server tetap memvalidasi pivot). Banyak SPPG: Mini App menampilkan pemilih. */
    private function petunjukSppg(User $user): ?int
    {
        if (! $user->isInvestor()) {
            return null;
        }

        $milik = app(SppgAktif::class)->milik($user);

        return count($milik) === 1 ? $milik[0] : null;
    }
}
