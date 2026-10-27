<?php

namespace App\Services\Telegram\Bot\Layar;

use App\Models\User;
use App\Services\Ai\Tools\ToolRegistry;
use App\Services\Telegram\Bot\BotLayar;
use App\Services\Telegram\Bot\BotLayarRegistry;
use App\Services\Telegram\Bot\BotTombol;
use App\Services\Telegram\TelegramBotService;

/**
 * Petunjuk "Tanya Agen AI" (layar `ai`): bukan mode khusus; teks bebas berikutnya sudah diteruskan ke Agen AI
 * oleh TelegramUpdateHandler. Menampilkan 2-3 contoh kalimat sesuai tool yang tersedia untuk peran pengguna
 * (ToolRegistry::forUser, sama dengan jalur LLM). Tanpa tool tersedia, layar dan tombolnya tidak ada.
 */
final class LayarTanyaAi extends LayarBacaDasar
{
    /**
     * Contoh kalimat per nama tool, urut prioritas (fiktif, tanpa data pribadi).
     *
     * @var array<string, string>
     */
    public const CONTOH = [
        'cari_jadwal_produksi' => 'Jadwal produksi hari ini',
        'cari_jadwal_distribusi' => 'Jadwal distribusi hari ini',
        'cari_bahan_baku' => 'Bahan baku apa yang stoknya menipis?',
        'cari_keluhan' => 'Keluhan yang masih terbuka',
        'daftar_pengajuan_menu' => 'Pengajuan menu yang menunggu persetujuan',
        'cari_pengeluaran' => 'Pengeluaran bulan ini',
        'cari_pencairan_dana' => 'Pencairan dana bulan ini',
        'cari_menu' => 'Menu hari ini',
        'cari_pemeriksaan_mutu' => 'Hasil pemeriksaan mutu terbaru',
        'cari_rekap_harian' => 'Rekap harian kemarin',
        'cari_armada' => 'Armada yang tersedia',
        'cari_titik_layanan' => 'Cari titik layanan SD',
        'catat_mutasi_stok' => 'Catat stok beras masuk 50 kg (menjadi draf)',
        'buat_pengeluaran' => 'Catat pengeluaran gas elpiji Rp 150.000 (menjadi draf)',
    ];

    public const MAKS_CONTOH = 3;

    public function id(): string
    {
        return BotLayarRegistry::TANYA_AI;
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

    /** Ada minimal satu tool Agen AI untuk peran pengguna saat ini. */
    public static function tersediaUntuk(User $user): bool
    {
        return app(ToolRegistry::class)->forUser($user) !== [];
    }

    public function izinkan(User $user, ?int $sppgId): bool
    {
        return self::tersediaUntuk($user);
    }

    public function render(User $user, ?int $sppgId, KonteksLayar $konteks): BotLayar
    {
        $tersedia = app(ToolRegistry::class)->forUser($user);
        $contoh = [];

        foreach (self::CONTOH as $tool => $kalimat) {
            if (isset($tersedia[$tool])) {
                $contoh[] = '- '.TelegramBotService::escapeHtml($kalimat);
            }

            if (count($contoh) >= self::MAKS_CONTOH) {
                break;
            }
        }

        $teks = "<b>Tanya Agen AI</b>\nKetik pertanyaan atau permintaan langsung di chat ini; tidak perlu menekan tombol lagi. Kirim foto nota atau pesan suara juga bisa.";

        if ($contoh !== []) {
            $teks .= "\n\nContoh:\n".implode("\n", $contoh);
        }

        $teks .= "\n\nPermintaan mengubah data hanya menjadi draf. Periksa dan simpan drafnya di Mini App.";

        return new BotLayar($teks, ['inline_keyboard' => BotTombol::kaki($konteks, $this->id(), $this->induk(), $sppgId)], true, true);
    }
}
