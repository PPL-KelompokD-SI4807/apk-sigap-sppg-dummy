<?php

namespace App\Services\Telegram\Bot\Layar;

use App\Models\PencairanDana;
use App\Models\Pengeluaran;
use App\Models\User;
use App\Services\Keuangan\BudgetService;
use App\Services\Telegram\Bot\BotLayar;

/**
 * Anggaran (layar `ag`, /anggaran): serapan periode berjalan dengan ambang peringatan 80% dan 100% dari
 * BudgetService::ringkasan (sppg_id eksplisit) + rincian per kategori. Hanya nominal agregat.
 */
final class LayarAnggaran extends LayarModulDasar
{
    public function __construct(private readonly BudgetService $anggaran) {}

    public function id(): string
    {
        return 'ag';
    }

    public function modul(): ?string
    {
        return 'keuangan';
    }

    protected function judul(): string
    {
        return 'Anggaran';
    }

    protected function rute(): ?string
    {
        return '/keuangan';
    }

    public function jedaDetik(): int
    {
        return LayarKeuangan::jedaBerat();
    }

    public function izinkan(User $user, ?int $sppgId): bool
    {
        return $this->bolehLihat($user, PencairanDana::class, Pengeluaran::class);
    }

    public function render(User $user, ?int $sppgId, KonteksLayar $konteks): BotLayar
    {
        $r = $this->anggaran->ringkasan((int) $sppgId, $this->anggaran->periodeBerjalan());
        $ambang = (int) config('operasional.keuangan.ambang_peringatan_persen', 80);
        $persen = $r['persen'];
        $isi = array_map(fn (string $b) => '- '.self::e($b), LayarKeuangan::barisRingkasan($r));
        $isi[] = '- '.self::e(sprintf('Ambang %d%%: %s; ambang 100%%: %s', $ambang, $persen !== null && $persen >= $ambang ? 'TERCAPAI' : 'belum', $persen !== null && $persen >= 100 ? 'TERCAPAI' : 'belum'));

        $kategori = array_filter($r['per_kategori'], fn (array $k) => $k['nominal'] > 0);

        if ($kategori !== []) {
            $isi[] = "\n<b>Per kategori (disetujui)</b>";

            foreach ($kategori as $k) {
                $isi[] = '- '.self::e(sprintf('%s: %s (%s%%)', $k['label'], FormatLayar::rupiah($k['nominal']), FormatLayar::angka($k['persen'], 1)));
            }
        }

        return $this->susun($user, $sppgId, $konteks, $isi);
    }
}
