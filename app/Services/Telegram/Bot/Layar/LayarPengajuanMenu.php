<?php

namespace App\Services\Telegram\Bot\Layar;

use App\Models\User;
use App\Services\Ai\Tools\Persetujuan\DaftarPengajuanMenuTool;
use App\Services\Telegram\Bot\BotLayar;
use App\Services\Telegram\Bot\BotLayarService;

/**
 * Persetujuan Menu (layar `pj`, pintasan v1): daftar pengajuan menu ke investor (nama menu, nomor, status, porsi,
 * biaya) lewat tool baca `daftar_pengajuan_menu` (SPPG dibatasi SppgBound/SppgScope; admin baca-saja dipersempit
 * ke SPPG terpilih). Catatan keputusan TIDAK ditampilkan. Keputusan investor tetap lewat tombol `b1` sekali pakai
 * ("Menunggu keputusan"); layar ini hanya membaca.
 */
final class LayarPengajuanMenu extends LayarModulDasar
{
    private const KOLOM = ['nama_menu', 'nomor', 'status', 'jumlah_porsi', 'biaya_per_porsi', 'total_biaya', 'diajukan_pada'];

    public function __construct(private readonly BotLayarService $layanan) {}

    public function id(): string
    {
        return 'pj';
    }

    public function modul(): ?string
    {
        return 'persetujuan-menu';
    }

    protected function judul(): string
    {
        return 'Persetujuan Menu';
    }

    protected function rute(): ?string
    {
        return '/persetujuan-menu';
    }

    public function izinkan(User $user, ?int $sppgId): bool
    {
        return (new DaftarPengajuanMenuTool)->isAvailableFor($user);
    }

    public function render(User $user, ?int $sppgId, KonteksLayar $konteks): BotLayar
    {
        $hasil = $this->layanan->daftarTool($user, new DaftarPengajuanMenuTool, [], self::KOLOM, $konteks->halaman);
        $isi = [];

        foreach ($hasil['baris'] ?? [] as $b) {
            $isi[] = '- '.self::e(sprintf(
                '#%s %s - %s, %s porsi x %s = %s (%s)',
                $b['nomor'] ?? '-',
                FormatLayar::nama($b['nama_menu'] ?? null),
                FormatLayar::label($b['status'] ?? null),
                FormatLayar::angka($b['jumlah_porsi'] ?? null),
                FormatLayar::rupiah($b['biaya_per_porsi'] ?? 0),
                FormatLayar::rupiah($b['total_biaya'] ?? 0),
                FormatLayar::tanggal($b['diajukan_pada'] ?? null),
            ));
        }

        if ($isi === []) {
            $isi[] = 'Belum ada pengajuan menu.';
        }

        return $this->susun($user, $sppgId, $konteks, $isi, true, (bool) ($hasil['ada_lagi'] ?? false));
    }
}
