<?php

namespace App\Services\Dashboard\Widget;

use App\Enums\StatusJadwalProduksi;
use App\Services\Dashboard\Cakupan;

/** Widget produksi: jadwal, target vs realisasi, tren porsi, log produksi. Hanya agregat/daftar operasional (tanpa data pribadi). */
final class ProduksiWidgets
{
    use Format;

    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(private readonly Cakupan $c, private readonly DistribusiWidgets $distribusi) {}

    /** @return array<string, array{jumlah: int, target: int}> */
    private function jadwalPerStatus(): array
    {
        return $this->memo['jadwal'] ??= (function (): array {
            $hasil = [];

            foreach ($this->c->pada($this->c->tabel('jadwal_produksis'), 'jadwal_produksis.tanggal_produksi')
                ->selectRaw('status, COUNT(*) as jumlah, COALESCE(SUM(target_porsi), 0) as target')
                ->groupBy('status')->get() as $r) {
                $hasil[(string) $r->status] = ['jumlah' => (int) $r->jumlah, 'target' => (int) $r->target];
            }

            return $hasil;
        })();
    }

    /** @return array{jumlah: int, realisasi: int, sisa: float} */
    private function logHariIni(): array
    {
        return $this->memo['log'] ??= (function (): array {
            $r = $this->c->pada(
                $this->c->tabel('log_produksis')->join('jadwal_produksis as jp', 'jp.id', '=', 'log_produksis.jadwal_produksi_id'),
                'jp.tanggal_produksi',
            )->whereNull('jp.deleted_at')->where('jp.status', '!=', StatusJadwalProduksi::Dibatalkan->value)
                ->selectRaw('COUNT(*) as jumlah, COALESCE(SUM(log_produksis.realisasi_porsi), 0) as realisasi, COALESCE(SUM(log_produksis.sisa_makanan_kg), 0) as sisa')
                ->first();

            return ['jumlah' => (int) ($r->jumlah ?? 0), 'realisasi' => (int) ($r->realisasi ?? 0), 'sisa' => round((float) ($r->sisa ?? 0), 2)];
        })();
    }

    /**
     * Jadwal aktif (tidak dibatalkan) dan target porsinya hari ini.
     *
     * @return array{0: int, 1: int}
     */
    private function aktif(): array
    {
        $jumlah = 0;
        $target = 0;

        foreach ($this->jadwalPerStatus() as $status => $v) {
            if ($status !== StatusJadwalProduksi::Dibatalkan->value) {
                $jumlah += $v['jumlah'];
                $target += $v['target'];
            }
        }

        return [$jumlah, $target];
    }

    /** @return array<string, mixed> Status jadwal produksi hari ini (semua status, terisi nol). */
    public function jadwalHariIni(): array
    {
        $per = $this->jadwalPerStatus();
        [$aktif, $target] = $this->aktif();

        return $this->widget($this->c->periodeHariIni(), [
            'total' => array_sum(array_column($per, 'jumlah')),
            'aktif' => $aktif,
            'target_porsi' => $target,
            'per_status' => array_map(fn (StatusJadwalProduksi $s) => [
                'status' => $s->value,
                'label' => $s->label(),
                'jumlah' => $per[$s->value]['jumlah'] ?? 0,
            ], StatusJadwalProduksi::cases()),
        ]);
    }

    /**
     * Porsi diproduksi (target vs realisasi). $denganDistribusi menambahkan porsi didistribusikan/diterima
     * (agregat yang juga termuat dalam ringkasan rekap harian).
     *
     * @return array<string, mixed>
     */
    public function porsiHariIni(bool $denganDistribusi = false): array
    {
        [$aktif, $target] = $this->aktif();
        $log = $this->logHariIni();

        $isi = [
            'target' => $target,
            'realisasi' => $log['realisasi'],
            'capaian_persen' => $this->persen($log['realisasi'], $target),
            'jadwal_aktif' => $aktif,
            'jadwal_dengan_log' => $log['jumlah'],
            'jadwal_tanpa_log' => max(0, $aktif - $log['jumlah']),
            'sisa_makanan_kg' => $log['sisa'],
        ];

        if ($denganDistribusi) {
            $d = $this->distribusi->porsi();
            $isi += [
                'didistribusikan' => $d['didistribusikan'],
                'diterima' => $d['diterima'],
                'diterima_persen_dari_diproduksi' => $this->persen($d['diterima'], $log['realisasi']),
            ];
        }

        return $this->widget($this->c->periodeHariIni(), $isi);
    }

    /** @return array<string, mixed> Tren porsi 7/30 hari: target, realisasi, diterima (distribusi). */
    public function trenPorsi(): array
    {
        $dari = $this->c->mulaiTren();
        $sampai = $this->c->hari();

        $peta = function ($q, string $kolomTanggal, string $ekspresi): array {
            $hasil = [];

            foreach ($q->selectRaw("DATE({$kolomTanggal}) as hari, COALESCE(SUM({$ekspresi}), 0) as n")->groupBy('hari')->get() as $r) {
                $hasil[$this->hari($r->hari)] = (int) $r->n;
            }

            return $hasil;
        };

        $target = $peta($this->c->antara($this->c->tabel('jadwal_produksis'), 'jadwal_produksis.tanggal_produksi', $dari, $sampai)
            ->where('status', '!=', StatusJadwalProduksi::Dibatalkan->value), 'jadwal_produksis.tanggal_produksi', 'jadwal_produksis.target_porsi');

        $realisasi = $peta($this->c->antara(
            $this->c->tabel('log_produksis')->join('jadwal_produksis as jp', 'jp.id', '=', 'log_produksis.jadwal_produksi_id'),
            'jp.tanggal_produksi', $dari, $sampai,
        )->whereNull('jp.deleted_at')->where('jp.status', '!=', StatusJadwalProduksi::Dibatalkan->value), 'jp.tanggal_produksi', 'log_produksis.realisasi_porsi');

        $diterima = $peta($this->c->antara(
            $this->c->tabel('bukti_distribusis')->join('jadwal_distribusis as jd', 'jd.id', '=', 'bukti_distribusis.jadwal_distribusi_id'),
            'jd.tanggal_distribusi', $dari, $sampai,
        )->whereNull('jd.deleted_at')->where('jd.status', '!=', 'dibatalkan'), 'jd.tanggal_distribusi', 'bukti_distribusis.porsi_diterima');

        return $this->widget($this->c->periodeTren(), [
            'labels' => $this->c->hariTren(),
            'series' => [
                'target' => $this->c->deret($target),
                'realisasi' => $this->c->deret($realisasi),
                'diterima' => $this->c->deret($diterima),
            ],
        ]);
    }

    /**
     * Jadwal produksi hari ini + menu + ada/tidaknya log (maks. 50 baris).
     *
     * @return list<object>
     */
    private function barisJadwal(): array
    {
        return $this->memo['baris'] ??= $this->c->pada($this->c->tabel('jadwal_produksis'), 'jadwal_produksis.tanggal_produksi')
            ->join('menus', 'menus.id', '=', 'jadwal_produksis.menu_id')
            ->leftJoin('log_produksis', fn ($j) => $j->on('log_produksis.jadwal_produksi_id', '=', 'jadwal_produksis.id')->whereNull('log_produksis.deleted_at'))
            ->where('jadwal_produksis.status', '!=', StatusJadwalProduksi::Dibatalkan->value)
            ->orderBy('jadwal_produksis.jam_mulai_masak')->orderBy('jadwal_produksis.id')->limit(50)
            ->get([
                'jadwal_produksis.id as jadwal_id', 'menus.nama_menu', 'menus.kelompok_sasaran', 'jadwal_produksis.target_porsi',
                'jadwal_produksis.status', 'jadwal_produksis.jam_mulai_masak', 'log_produksis.id as log_id',
                'log_produksis.realisasi_porsi', 'log_produksis.jam_selesai',
            ])->all();
    }

    /** @return array<string, mixed> Menu dan target porsi hari ini (relawan dapur). */
    public function menuTargetHariIni(): array
    {
        $items = array_map(fn ($r) => [
            'jadwal_id' => (int) $r->jadwal_id,
            'menu' => (string) $r->nama_menu,
            'kelompok_sasaran' => (string) $r->kelompok_sasaran,
            'target_porsi' => (int) $r->target_porsi,
            'status' => (string) $r->status,
            'status_label' => StatusJadwalProduksi::tryFrom((string) $r->status)?->label(),
            'jam_mulai_masak' => $r->jam_mulai_masak === null ? null : substr((string) $r->jam_mulai_masak, 0, 5),
        ], $this->barisJadwal());

        return $this->widget($this->c->periodeHariIni(), [
            'jumlah_jadwal' => count($items),
            'total_target_porsi' => array_sum(array_column($items, 'target_porsi')),
            'items' => $items,
        ]);
    }

    /** @return array<string, mixed> Status log produksi hari ini per jadwal (relawan dapur). */
    public function statusLogProduksi(): array
    {
        $items = array_map(fn ($r) => [
            'jadwal_id' => (int) $r->jadwal_id,
            'menu' => (string) $r->nama_menu,
            'target_porsi' => (int) $r->target_porsi,
            'ada_log' => $r->log_id !== null,
            'realisasi_porsi' => $r->log_id === null ? null : (int) $r->realisasi_porsi,
            'jam_selesai' => $r->jam_selesai === null ? null : substr((string) $r->jam_selesai, 0, 5),
        ], $this->barisJadwal());
        $sudah = count(array_filter($items, fn (array $i) => $i['ada_log']));

        return $this->widget($this->c->periodeHariIni(), [
            'jumlah_jadwal' => count($items),
            'sudah_ada_log' => $sudah,
            'belum_ada_log' => count($items) - $sudah,
            'items' => $items,
        ]);
    }
}
