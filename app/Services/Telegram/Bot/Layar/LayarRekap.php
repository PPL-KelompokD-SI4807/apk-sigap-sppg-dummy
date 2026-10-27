<?php

namespace App\Services\Telegram\Bot\Layar;

use App\Models\RekapHarian;
use App\Models\User;
use App\Services\Rekap\DailySummaryService;
use App\Services\Telegram\Bot\BotLayar;
use App\Services\Telegram\Bot\BotLayarRegistry;
use App\Services\Telegram\Bot\BotLayarService;

/**
 * Rekap Harian (layar `rk`, /rekap): ringkasan hari ini dari DailySummaryService::hitung (sppg_id eksplisit, tanpa
 * cache; layar berat dijeda) + status rekap tersimpan terakhir lewat tool baca `cari_rekap_harian`. Tiap bagian
 * hanya disusun bila peran berhak membaca modulnya (aturan sama dengan BotNavigasi/BotLayarRegistry::hakRbac);
 * hanya hitungan, persentase, dan nominal. Tanpa nama orang, kontak, uraian, atau catatan rekap.
 */
final class LayarRekap extends LayarModulDasar
{
    public function __construct(
        private readonly DailySummaryService $harian,
        private readonly BotLayarService $layanan,
    ) {}

    public function id(): string
    {
        return 'rk';
    }

    public function modul(): ?string
    {
        return 'rekap-harian';
    }

    protected function judul(): string
    {
        return 'Rekap Harian';
    }

    protected function rute(): ?string
    {
        return '/rekap-harian';
    }

    public function jedaDetik(): int
    {
        return LayarKeuangan::jedaBerat();
    }

    public function izinkan(User $user, ?int $sppgId): bool
    {
        return $this->bolehLihat($user, RekapHarian::class);
    }

    public function render(User $user, ?int $sppgId, KonteksLayar $konteks): BotLayar
    {
        $h = $this->harian->hitung((int) $sppgId);
        $baca = fn (string $modul): bool => BotLayarRegistry::hakRbac($user, $modul, 'R');
        $n = fn (mixed $v): string => FormatLayar::angka($v);
        $isi = ['Hari ini: '.self::e(FormatLayar::tanggal($h['tanggal']))];

        if ($baca('jadwal')) {
            $p = $h['porsi'];
            $baris = sprintf('Produksi: %s jadwal, target %s porsi', $n($p['jumlah_jadwal']), $n($p['target']));

            if ($baca('log-produksi')) {
                $baris .= sprintf(', realisasi %s (%s%%), %s jadwal belum ada log', $n($p['realisasi']), FormatLayar::angka($p['capaian_persen'], 1), $n($p['jadwal_tanpa_log']));
            }

            $isi[] = '- '.self::e($baris);
        }

        if ($baca('mutu')) {
            $m = $h['mutu'];
            $isi[] = '- '.self::e(sprintf('Mutu: %s batch, %s layak, %s tidak layak, %s belum diperiksa', $n($m['batch']), $n($m['layak']), $n($m['tidak_layak']), $n($m['belum_diperiksa'])));
        }

        if ($baca('jadwal') || $baca('bukti-distribusi')) {
            $d = $h['distribusi'];
            $isi[] = '- '.self::e(sprintf('Distribusi: %s jadwal, %s tiba, %s terlambat, %s batal, %s belum selesai', $n($d['total']), $n($d['tiba']), $n($d['terlambat']), $n($d['batal']), $n($d['belum_selesai'])));
        }

        if ($baca('bahan-baku')) {
            $s = $h['stok'];
            $isi[] = '- '.self::e(sprintf('Stok: %s menipis, %s segera kedaluwarsa, %s sudah kedaluwarsa', $n($s['menipis']), $n($s['akan_kedaluwarsa']), $n($s['sudah_kedaluwarsa'])));
        }

        if ($baca('keluhan')) {
            $k = $h['keluhan'];
            $isi[] = '- '.self::e(sprintf('Keluhan: %s baru hari ini (%s kritis), %s masih terbuka', $n($k['baru']), $n($k['kritis']), $n($k['terbuka'])));
        }

        if ($baca('keuangan')) {
            $u = $h['keuangan'];
            $isi[] = '- '.self::e(sprintf(
                'Keuangan %s: pencairan %s, pengeluaran hari ini %s, pengeluaran periode %s, sisa %s (serapan %s%%)',
                $u['periode'],
                FormatLayar::rupiah($u['pencairan_periode']),
                FormatLayar::rupiah($u['pengeluaran_hari_ini']),
                FormatLayar::rupiah($u['pengeluaran_periode']),
                FormatLayar::rupiah($u['sisa_anggaran']),
                FormatLayar::angka($u['serapan_persen'], 1),
            ));
        }

        $terakhir = $this->layanan->daftar($user, 'rekap_harian', [], ['tanggal', 'status_kirim'], 0);
        $rekap = $terakhir['baris'][0] ?? null;

        if ($rekap !== null) {
            $isi[] = '- '.self::e(sprintf('Rekap tersimpan terakhir: %s (%s)', FormatLayar::tanggal($rekap['tanggal'] ?? null), FormatLayar::label($rekap['status_kirim'] ?? null)));
        }

        return $this->susun($user, $sppgId, $konteks, $isi);
    }
}
