<?php

namespace App\Services\Telegram\Bot\Layar;

use App\Models\BotAksi;
use App\Models\JadwalDistribusi;
use App\Models\User;
use App\Services\Telegram\Bot\BotAksiTulisService;
use App\Services\Telegram\Bot\BotLayar;

/**
 * "Mulai Perjalanan" (layar `mp`, R-02 PR-3): jadwal distribusi HARI INI yang siap berangkat, masing-masing dengan
 * tombol aksi `b1` "Berangkat: <tujuan>" -> layar konfirmasi (tujuan, estimasi tiba, penerima notifikasi) -> mulai.
 *
 * Hanya kepala_sppg dan relawan unit distribusi (notifikasi-perjalanan C, Policy create); admin (R saja) dan investor
 * tidak pernah. Baca: notifikasi-perjalanan R (BotNavigasi) + izinkan() di bawah. Tanpa nama pengemudi/PIC/kontak.
 */
final class LayarMulaiPerjalanan extends LayarModulDasar implements LayarAksi
{
    public function __construct(private readonly BotAksiTulisService $tulis) {}

    public function id(): string
    {
        return BotAksiTulisService::LAYAR_MULAI_PERJALANAN;
    }

    public function modul(): ?string
    {
        return 'notifikasi-perjalanan';
    }

    protected function judul(): string
    {
        return 'Mulai Perjalanan';
    }

    protected function rute(): ?string
    {
        return '/notifikasi-perjalanan';
    }

    /** Layar berat (query + aturan keberangkatan per jadwal): dijeda seperti Rekap/Keuangan (E2-7). */
    public function jedaDetik(): int
    {
        return LayarKeuangan::jedaBerat();
    }

    public function tampilUntuk(User $user): bool
    {
        return $this->tulis->bolehAksi($user, BotAksi::JENIS_PICU_PERJALANAN);
    }

    public function izinkan(User $user, ?int $sppgId): bool
    {
        return $this->tampilUntuk($user) && $this->bolehLihat($user, JadwalDistribusi::class);
    }

    public function render(User $user, ?int $sppgId, KonteksLayar $konteks): BotLayar
    {
        $data = $this->tulis->daftarBerangkat($user, (int) $sppgId, $konteks);

        return $this->susun($user, $sppgId, $konteks, [
            'Jadwal distribusi hari ini:',
            ...$data['isi'],
            '',
            'Tekan tombol tujuan untuk melihat estimasi tiba dan penerima notifikasi sebelum berangkat.',
        ], barisTombol: $data['tombol']);
    }
}
