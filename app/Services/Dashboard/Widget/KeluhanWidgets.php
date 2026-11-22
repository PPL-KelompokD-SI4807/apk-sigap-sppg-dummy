<?php

namespace App\Services\Dashboard\Widget;

use App\Enums\PrioritasKeluhan;
use App\Enums\StatusKeluhan;
use App\Services\Dashboard\Cakupan;

/** Widget feedback dan keluhan. Hanya hitungan (teks keluhan/foto bisa memuat data anak dan tidak pernah disertakan). */
final class KeluhanWidgets
{
    use Format;

    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(private readonly Cakupan $c) {}

    /** @return array<string, array<string, int>> prioritas => [status => jumlah] untuk keluhan yang masih terbuka */
    private function terbukaPerPrioritas(): array
    {
        return $this->memo['terbuka'] ??= (function (): array {
            $hasil = [];

            foreach ($this->c->tabel('feedback_keluhans')->whereIn('feedback_keluhans.status', StatusKeluhan::terbukaValues())
                ->selectRaw('prioritas, status, COUNT(*) as jumlah')->groupBy('prioritas', 'status')->get() as $r) {
                $hasil[(string) $r->prioritas][(string) $r->status] = (int) $r->jumlah;
            }

            return $hasil;
        })();
    }

    /** @return array{total: array<string, int>, kritis: array<string, int>} keluhan masuk per hari pada jendela tren */
    private function masukPerHari(): array
    {
        return $this->memo['masuk'] ??= (function (): array {
            $total = [];
            $kritis = [];

            foreach ($this->c->antara($this->c->tabel('feedback_keluhans'), 'feedback_keluhans.created_at', $this->c->mulaiTren(), $this->c->hari())
                ->selectRaw('DATE(feedback_keluhans.created_at) as hari, COUNT(*) as total, SUM(CASE WHEN feedback_keluhans.prioritas = ? THEN 1 ELSE 0 END) as kritis', [PrioritasKeluhan::Kritis->value])
                ->groupBy('hari')->get() as $r) {
                $total[$this->hari($r->hari)] = (int) $r->total;
                $kritis[$this->hari($r->hari)] = (int) $r->kritis;
            }

            return ['total' => $total, 'kritis' => $kritis];
        })();
    }

    private function terbukaDariPrioritas(PrioritasKeluhan $p): int
    {
        return array_sum($this->terbukaPerPrioritas()[$p->value] ?? []);
    }

    /** @return array<string, mixed> Keluhan terbuka (kepala SPPG). */
    public function terbuka(): array
    {
        $masuk = $this->masukPerHari();
        $perPrioritas = array_map(fn (PrioritasKeluhan $p) => [
            'prioritas' => $p->value,
            'label' => $p->label(),
            'jumlah' => $this->terbukaDariPrioritas($p),
        ], PrioritasKeluhan::cases());
        $baru = 0;
        $diproses = 0;

        foreach ($this->terbukaPerPrioritas() as $per) {
            $baru += $per[StatusKeluhan::Baru->value] ?? 0;
            $diproses += $per[StatusKeluhan::Diproses->value] ?? 0;
        }

        return $this->widget($this->c->periodeSaatIni(), [
            'terbuka' => $baru + $diproses,
            'baru' => $baru,
            'diproses' => $diproses,
            'kritis_terbuka' => $this->terbukaDariPrioritas(PrioritasKeluhan::Kritis),
            'masuk_hari_ini' => $masuk['total'][$this->c->hari()] ?? 0,
            'per_prioritas' => $perPrioritas,
            'tren' => [
                'periode' => $this->c->periodeTren(),
                'labels' => $this->c->hariTren(),
                'series' => ['masuk' => $this->c->deret($masuk['total']), 'kritis' => $this->c->deret($masuk['kritis'])],
            ],
        ]);
    }

    /** @return array<string, mixed> Keluhan kritis (investor). */
    public function kritis(): array
    {
        $masuk = $this->masukPerHari();

        return $this->widget($this->c->periodeSaatIni(), [
            'kritis_terbuka' => $this->terbukaDariPrioritas(PrioritasKeluhan::Kritis),
            'kritis_masuk_rentang' => array_sum($masuk['kritis']),
            'terbuka_semua_prioritas' => array_sum(array_map(fn (PrioritasKeluhan $p) => $this->terbukaDariPrioritas($p), PrioritasKeluhan::cases())),
            'tren' => [
                'periode' => $this->c->periodeTren(),
                'labels' => $this->c->hariTren(),
                'series' => ['kritis' => $this->c->deret($masuk['kritis'])],
            ],
        ]);
    }

    /** @return array{total: array<string, int>, kritis: array<string, int>} dipakai widget tren gabungan investor */
    public function perHari(): array
    {
        return $this->masukPerHari();
    }
}
