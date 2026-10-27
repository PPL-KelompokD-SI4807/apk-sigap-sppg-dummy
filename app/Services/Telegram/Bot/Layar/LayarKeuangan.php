<?php

namespace App\Services\Telegram\Bot\Layar;

use App\Models\PencairanDana;
use App\Models\Pengeluaran;
use App\Models\User;
use App\Services\Keuangan\BudgetService;
use App\Services\Telegram\Bot\BotLayar;
use App\Services\Telegram\Bot\BotLayarRegistry;

/**
 * Keuangan (layar `ku`, /keuangan): ringkasan anggaran periode berjalan dari BudgetService::ringkasan (sppg_id
 * eksplisit; hanya pengeluaran yang disetujui dihitung) + tombol ke Pencairan, Pengeluaran, dan Anggaran. Hanya
 * nominal agregat: tanpa uraian, nota, atau pemasok. Layar berat: dijeda per pengguna.
 */
final class LayarKeuangan extends LayarModulDasar
{
    public function __construct(private readonly BudgetService $anggaran) {}

    /** Jeda minimum (detik) layar berat (Rekap, Keuangan, Anggaran); config telegram.layar_berat_jeda_detik. */
    public static function jedaBerat(): int
    {
        return max(0, (int) config('telegram.layar_berat_jeda_detik', 3));
    }

    /**
     * Baris ringkasan anggaran (teks biasa) dari hasil BudgetService::ringkasan, termasuk status ambang 80/100%.
     *
     * @param  array<string, mixed>  $r
     * @return list<string>
     */
    public static function barisRingkasan(array $r): array
    {
        $ambang = (int) config('operasional.keuangan.ambang_peringatan_persen', 80);
        $status = match ($r['status']) {
            'peringatan' => "PERINGATAN: serapan mencapai {$ambang}%",
            'habis' => 'HABIS: serapan mencapai 100%',
            'melampaui' => 'MELEBIHI anggaran (lebih dari 100%)',
            'belum_ada_anggaran' => 'Belum ada pencairan dana pada periode ini',
            default => "Aman (di bawah {$ambang}%)",
        };

        return [
            'Periode: '.$r['periode'],
            'Anggaran (pencairan): '.FormatLayar::rupiah($r['anggaran']),
            'Terpakai (disetujui): '.FormatLayar::rupiah($r['terpakai']),
            'Sisa: '.FormatLayar::rupiah($r['sisa']),
            'Serapan: '.($r['persen'] === null ? '-' : FormatLayar::angka($r['persen'], 1).'%'),
            'Status: '.$status,
            sprintf('Menunggu persetujuan investor: %s pengeluaran (%s)', FormatLayar::angka($r['menunggu_persetujuan']['jumlah']), FormatLayar::rupiah($r['menunggu_persetujuan']['nominal'])),
        ];
    }

    public function id(): string
    {
        return 'ku';
    }

    public function modul(): ?string
    {
        return 'keuangan';
    }

    protected function judul(): string
    {
        return 'Keuangan';
    }

    protected function rute(): ?string
    {
        return '/keuangan';
    }

    public function jedaDetik(): int
    {
        return self::jedaBerat();
    }

    public function izinkan(User $user, ?int $sppgId): bool
    {
        return $this->bolehLihat($user, PencairanDana::class, Pengeluaran::class);
    }

    public function render(User $user, ?int $sppgId, KonteksLayar $konteks): BotLayar
    {
        $r = $this->anggaran->ringkasan((int) $sppgId, $this->anggaran->periodeBerjalan());
        $isi = array_map(fn (string $b) => '- '.self::e($b), self::barisRingkasan($r));
        $registry = app(BotLayarRegistry::class);
        $tombol = [];

        foreach (['pc' => 'Pencairan', 'pk' => 'Pengeluaran', 'ag' => 'Anggaran'] as $id => $label) {
            if ($registry->cari($id) !== null) {
                $tombol[] = $konteks->tombol($label, $id, $sppgId);
            }
        }

        return $this->susun($user, $sppgId, $konteks, $isi, barisTombol: $tombol === [] ? [] : [$tombol]);
    }
}
