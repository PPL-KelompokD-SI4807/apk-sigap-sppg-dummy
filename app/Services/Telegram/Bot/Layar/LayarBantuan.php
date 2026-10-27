<?php

namespace App\Services\Telegram\Bot\Layar;

use App\Models\User;
use App\Services\Telegram\Bot\BotLayar;
use App\Services\Telegram\Bot\BotLayarRegistry;

/** Bantuan (layar `bt`, perintah /bantuan): cara memakai bot. Teks statis; hanya sesi penuh (BotNavigasi). */
final class LayarBantuan extends LayarBacaDasar
{
    public const TEKS = "<b>Cara memakai SiGAP SPPG</b>\n"
        ."- Ketik /menu untuk beranda: ringkasan hari ini dan tombol sesuai peran Anda.\n"
        ."- Tombol \"Buka Mini App\" membuka Mini App untuk mengelola data.\n"
        ."- Perintah cepat: /jadwal /stok /keluhan /rekap /keuangan /anggaran /distribusi /mutu (hanya yang sesuai peran).\n"
        ."- Ketik permintaan biasa, mis. \"cari titik layanan SD\", atau kirim foto nota/pesan suara untuk dibantu Agen AI.\n"
        .'- Setiap draf wajib diperiksa dan disimpan di Mini App. /batal membatalkan proses alasan penolakan.';

    public function id(): string
    {
        return BotLayarRegistry::BANTUAN;
    }

    public function modul(): ?string
    {
        return null;
    }

    public function hak(): string
    {
        return self::HAK_SESI;
    }

    public function butuhSppg(): bool
    {
        return false;
    }

    public function render(User $user, ?int $sppgId, KonteksLayar $konteks): BotLayar
    {
        return new BotLayar(self::TEKS, ['inline_keyboard' => [[$konteks->tombol('Kembali', BotLayarRegistry::BERANDA, $sppgId)]]], true, true);
    }
}
