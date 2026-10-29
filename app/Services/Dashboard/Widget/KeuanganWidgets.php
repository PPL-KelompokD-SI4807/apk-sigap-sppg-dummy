<?php

namespace App\Services\Dashboard\Widget;

use App\Enums\KategoriPengeluaran;
use App\Enums\StatusPersetujuanPengeluaran;
use App\Models\Pengeluaran;
use App\Services\Dashboard\Cakupan;
use App\Services\Keuangan\BudgetService;

/**
 * Widget keuangan. Definisi mengikuti BudgetService::ringkasan: anggaran = pencairan periode (bulan
 * berjalan), terpakai = pengeluaran DISETUJUI di bulan itu. Semua nominal rupiah bulat (int).
 *
 * Seluruh angka dihitung SATU kali dalam query terkelompok per SPPG (bukan per SPPG satu per satu),
 * sehingga jumlah query tidak bertambah dengan banyaknya SPPG milik investor.
 */
final class KeuanganWidgets
{
    use Format;

    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(private readonly Cakupan $c, private readonly BudgetService $budget) {}

    /**
     * @return array{
     *     pencairan: array<int, array{nominal: int, jumlah: int}>,
     *     terpakai: array<int, int>,
     *     menunggu: array<int, array{jumlah: int, nominal: int}>,
     *     kategori: array<string, array{nominal: int, jumlah: int}>,
     *     dihitung: int
     * }
     */
    private function bulan(): array
    {
        return $this->memo['bulan'] ??= (function (): array {
            $pencairan = [];
            $terpakai = [];
            $menunggu = [];
            $kategori = [];
            $dihitung = 0;

            foreach ($this->c->tabel('pencairan_danas')->where('pencairan_danas.periode', $this->c->bulan())
                ->selectRaw('pencairan_danas.sppg_id as sppg_id, SUM(pencairan_danas.nominal) as nominal, COUNT(*) as jumlah')
                ->groupBy('pencairan_danas.sppg_id')->get() as $r) {
                $pencairan[(int) $r->sppg_id] = ['nominal' => $this->rupiah($r->nominal), 'jumlah' => (int) $r->jumlah];
            }

            foreach ($this->c->antara($this->c->tabel('pengeluarans'), 'pengeluarans.tanggal', $this->c->awalBulan(), $this->c->akhirBulan())
                ->selectRaw('pengeluarans.sppg_id as sppg_id, pengeluarans.kategori as kategori, pengeluarans.status_persetujuan as status, COUNT(*) as jumlah, SUM(pengeluarans.nominal) as nominal')
                ->groupBy('pengeluarans.sppg_id', 'pengeluarans.kategori', 'pengeluarans.status_persetujuan')->get() as $r) {
                $sppg = (int) $r->sppg_id;
                $nominal = $this->rupiah($r->nominal);

                if ($r->status === StatusPersetujuanPengeluaran::Disetujui->value) {
                    $terpakai[$sppg] = ($terpakai[$sppg] ?? 0) + $nominal;
                    $kategori[(string) $r->kategori]['nominal'] = ($kategori[(string) $r->kategori]['nominal'] ?? 0) + $nominal;
                    $kategori[(string) $r->kategori]['jumlah'] = ($kategori[(string) $r->kategori]['jumlah'] ?? 0) + (int) $r->jumlah;
                }

                if ($r->status === StatusPersetujuanPengeluaran::Diajukan->value) {
                    $menunggu[$sppg]['jumlah'] = ($menunggu[$sppg]['jumlah'] ?? 0) + (int) $r->jumlah;
                    $menunggu[$sppg]['nominal'] = ($menunggu[$sppg]['nominal'] ?? 0) + $nominal;
                }

                if ($r->status !== StatusPersetujuanPengeluaran::Ditolak->value) {
                    $dihitung += (int) $r->jumlah;
                }
            }

            return ['pencairan' => $pencairan, 'terpakai' => $terpakai, 'menunggu' => $menunggu, 'kategori' => $kategori, 'dihitung' => $dihitung];
        })();
    }

    /** @return array{anggaran: int, terpakai: int} */
    private function total(): array
    {
        $b = $this->bulan();

        return [
            'anggaran' => array_sum(array_column($b['pencairan'], 'nominal')),
            'terpakai' => array_sum($b['terpakai']),
        ];
    }

    /** @return array<string, mixed> */
    private function serapan(int $anggaran, int $terpakai): array
    {
        $ambang = max(1, (int) config('operasional.keuangan.ambang_peringatan_persen', 80));

        return [
            'anggaran' => $anggaran,
            'terpakai' => $terpakai,
            'sisa' => $anggaran - $terpakai,
            'persen' => $this->budget->persen($terpakai, $anggaran),
            'status' => $this->budget->status($terpakai, $anggaran),
            'penanda' => [
                'ambang_peringatan_persen' => $ambang,
                'mencapai_ambang' => $anggaran > 0 && $terpakai * 100 >= $anggaran * $ambang,
                'mencapai_100' => $anggaran > 0 && $terpakai >= $anggaran,
                'melampaui' => $anggaran > 0 && $terpakai > $anggaran,
            ],
        ];
    }

    /** @return array<string, mixed> Serapan anggaran bulan berjalan (gabungan; per SPPG bila lebih dari satu SPPG). */
    public function anggaran(): array
    {
        $b = $this->bulan();
        $t = $this->total();
        $nama = $this->c->namaSppg();
        $perSppg = null;

        if ($this->c->banyakSppg() > 1) {
            $perSppg = [];

            foreach ($this->c->ids as $id) {
                $perSppg[] = ['sppg_id' => $id, 'nama' => $nama[$id] ?? null] + $this->serapan($b['pencairan'][$id]['nominal'] ?? 0, $b['terpakai'][$id] ?? 0)
                    + ['menunggu_persetujuan' => $b['menunggu'][$id]['jumlah'] ?? 0];
            }
        }

        return $this->widget($this->c->periodeBulan(), $this->serapan($t['anggaran'], $t['terpakai']) + [
            'rencana_anggaran' => $t['anggaran'],
            'keterangan_rencana' => 'Rencana anggaran = total pencairan dana periode ini (belum ada tabel rencana terpisah).',
            'menunggu_persetujuan' => [
                'jumlah' => array_sum(array_column($b['menunggu'], 'jumlah')),
                'nominal' => array_sum(array_column($b['menunggu'], 'nominal')),
            ],
            'per_sppg' => $perSppg,
        ]);
    }

    /** @return array<string, mixed> Pencairan vs pengeluaran (akuntan). */
    public function pencairanVsPengeluaran(): array
    {
        $b = $this->bulan();
        $t = $this->total();

        return $this->widget($this->c->periodeBulan(), [
            'pencairan' => $t['anggaran'],
            'jumlah_pencairan' => array_sum(array_column($b['pencairan'], 'jumlah')),
            'pengeluaran' => $t['terpakai'],
            'jumlah_pengeluaran' => array_sum(array_column($b['kategori'], 'jumlah')),
            'selisih' => $t['anggaran'] - $t['terpakai'],
            'persen' => $this->budget->persen($t['terpakai'], $t['anggaran']),
            'status' => $this->budget->status($t['terpakai'], $t['anggaran']),
        ]);
    }

    /** @return array<string, mixed> Serapan per kategori pengeluaran (akuntan). */
    public function serapanPerKategori(): array
    {
        $b = $this->bulan();
        $t = $this->total();
        $items = array_map(fn (KategoriPengeluaran $k) => [
            'kategori' => $k->value,
            'label' => $k->label(),
            'nominal' => $b['kategori'][$k->value]['nominal'] ?? 0,
            'jumlah' => $b['kategori'][$k->value]['jumlah'] ?? 0,
            'persen_dari_terpakai' => $this->persen($b['kategori'][$k->value]['nominal'] ?? 0, $t['terpakai']),
            'persen_dari_anggaran' => $this->persen($b['kategori'][$k->value]['nominal'] ?? 0, $t['anggaran']),
        ], KategoriPengeluaran::cases());

        return $this->widget($this->c->periodeBulan(), [
            'anggaran' => $t['anggaran'],
            'terpakai' => $t['terpakai'],
            'items' => $items,
            'labels' => array_column($items, 'label'),
            'series' => ['nominal' => array_column($items, 'nominal')],
        ]);
    }

    /**
     * Pengajuan yang masih terbuka (tanpa batas tanggal): pengeluaran menunggu investor (+ opsional pengajuan menu
     * dan pembelian bahan menunggu verifikasi), digabung per SPPG.
     *
     * @return array{pengeluaran: array<int, array{jumlah: int, nominal: int}>, menu: array<int, int>, pembelian: array<int, array{jumlah: int, nominal: int}>}
     */
    private function terbuka(): array
    {
        return $this->memo['terbuka'] ??= (function (): array {
            $pengeluaran = [];
            $menu = [];
            $pembelian = [];

            foreach ($this->c->tabel('pengeluarans')->where('pengeluarans.status_persetujuan', StatusPersetujuanPengeluaran::Diajukan->value)
                ->selectRaw('pengeluarans.sppg_id as sppg_id, COUNT(*) as jumlah, SUM(pengeluarans.nominal) as nominal')
                ->groupBy('pengeluarans.sppg_id')->get() as $r) {
                $pengeluaran[(int) $r->sppg_id] = ['jumlah' => (int) $r->jumlah, 'nominal' => $this->rupiah($r->nominal)];
            }

            foreach ($this->c->tabel('pengajuan_menus', false)->where('pengajuan_menus.status', 'diajukan')
                ->selectRaw('pengajuan_menus.sppg_id as sppg_id, COUNT(*) as jumlah')->groupBy('pengajuan_menus.sppg_id')->get() as $r) {
                $menu[(int) $r->sppg_id] = (int) $r->jumlah;
            }

            foreach ($this->c->tabel('pembelian_bahans')->where('pembelian_bahans.status', 'diajukan')
                ->selectRaw('pembelian_bahans.sppg_id as sppg_id, COUNT(*) as jumlah, SUM(pembelian_bahans.total) as nominal')
                ->groupBy('pembelian_bahans.sppg_id')->get() as $r) {
                $pembelian[(int) $r->sppg_id] = ['jumlah' => (int) $r->jumlah, 'nominal' => $this->rupiah($r->nominal)];
            }

            return ['pengeluaran' => $pengeluaran, 'menu' => $menu, 'pembelian' => $pembelian];
        })();
    }

    /** @return array<string, mixed> Pengajuan menunggu persetujuan investor (investor, kepala SPPG). */
    public function pengajuanMenunggu(): array
    {
        $o = $this->terbuka();
        $nama = $this->c->namaSppg();
        $perSppg = null;

        if ($this->c->banyakSppg() > 1) {
            $perSppg = [];

            foreach ($this->c->ids as $id) {
                $perSppg[] = [
                    'sppg_id' => $id,
                    'nama' => $nama[$id] ?? null,
                    'pengeluaran' => $o['pengeluaran'][$id]['jumlah'] ?? 0,
                    'menu' => $o['menu'][$id] ?? 0,
                ];
            }
        }

        $pengeluaran = ['jumlah' => array_sum(array_column($o['pengeluaran'], 'jumlah')), 'nominal' => array_sum(array_column($o['pengeluaran'], 'nominal'))];
        $menu = array_sum($o['menu']);

        return $this->widget($this->c->periodeSaatIni(), [
            'total' => $pengeluaran['jumlah'] + $menu,
            'pengeluaran' => $pengeluaran,
            'menu' => ['jumlah' => $menu],
            'per_sppg' => $perSppg,
        ]);
    }

    /** @return array<string, mixed> Pengeluaran menunggu persetujuan dan pembelian menunggu verifikasi (akuntan). */
    public function pengeluaranMenunggu(): array
    {
        $o = $this->terbuka();

        return $this->widget($this->c->periodeSaatIni(), [
            'pengeluaran' => ['jumlah' => array_sum(array_column($o['pengeluaran'], 'jumlah')), 'nominal' => array_sum(array_column($o['pengeluaran'], 'nominal'))],
            'pembelian_menunggu_verifikasi' => ['jumlah' => array_sum(array_column($o['pembelian'], 'jumlah')), 'nominal' => array_sum(array_column($o['pembelian'], 'nominal'))],
        ]);
    }

    /**
     * Pengeluaran bulan berjalan (diajukan atau disetujui) yang belum punya bukti nota. Bukti dianggap ada bila
     * nota_path terisi, ada lampiran terikat ke pengeluaran, atau pengeluaran berasal dari pembelian bahan yang
     * notanya terisi. Hanya hitungan; berkas nota tidak pernah dilayani lewat dashboard.
     *
     * @return array<string, mixed>
     */
    public function buktiNota(): array
    {
        $morph = (new Pengeluaran)->getMorphClass();

        $r = $this->c->antara($this->c->tabel('pengeluarans'), 'pengeluarans.tanggal', $this->c->awalBulan(), $this->c->akhirBulan())
            ->whereIn('pengeluarans.status_persetujuan', [StatusPersetujuanPengeluaran::Diajukan->value, StatusPersetujuanPengeluaran::Disetujui->value])
            ->where(fn ($q) => $q->whereNull('pengeluarans.nota_path')->orWhere('pengeluarans.nota_path', ''))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('lampirans')
                ->whereColumn('lampirans.lampiranable_id', 'pengeluarans.id')->where('lampirans.lampiranable_type', $morph))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('pembelian_bahans')
                ->whereColumn('pembelian_bahans.id', 'pengeluarans.pembelian_bahan_id')
                ->whereNotNull('pembelian_bahans.nota_path')->where('pembelian_bahans.nota_path', '<>', ''))
            ->selectRaw('COUNT(*) as jumlah, SUM(pengeluarans.nominal) as nominal')->first();

        $tanpa = (int) ($r->jumlah ?? 0);
        $dihitung = $this->bulan()['dihitung'];

        return $this->widget($this->c->periodeBulan(), [
            'pengeluaran_dihitung' => $dihitung,
            'bukti_belum_ada' => ['jumlah' => $tanpa, 'nominal' => $this->rupiah($r->nominal ?? 0)],
            'persen_bukti_belum_ada' => $this->persen($tanpa, $dihitung),
        ]);
    }

    /**
     * Pencairan dan pengeluaran disetujui per hari pada jendela tren.
     *
     * @return array{pencairan: array<string, int>, pengeluaran: array<string, int>}
     */
    public function harian(): array
    {
        return $this->memo['harian'] ??= (function (): array {
            $pencairan = [];
            $pengeluaran = [];

            foreach ($this->c->antara($this->c->tabel('pencairan_danas'), 'pencairan_danas.tanggal_cair', $this->c->mulaiTren(), $this->c->hari())
                ->selectRaw('DATE(pencairan_danas.tanggal_cair) as hari, SUM(pencairan_danas.nominal) as nominal')->groupBy('hari')->get() as $r) {
                $pencairan[$this->hari($r->hari)] = $this->rupiah($r->nominal);
            }

            foreach ($this->c->antara($this->c->tabel('pengeluarans'), 'pengeluarans.tanggal', $this->c->mulaiTren(), $this->c->hari())
                ->where('pengeluarans.status_persetujuan', StatusPersetujuanPengeluaran::Disetujui->value)
                ->selectRaw('DATE(pengeluarans.tanggal) as hari, SUM(pengeluarans.nominal) as nominal')->groupBy('hari')->get() as $r) {
                $pengeluaran[$this->hari($r->hari)] = $this->rupiah($r->nominal);
            }

            return ['pencairan' => $pencairan, 'pengeluaran' => $pengeluaran];
        })();
    }

    /** @return array<string, mixed> Selisih pencairan - pengeluaran dan tren harian (akuntan). */
    public function selisihTren(): array
    {
        $h = $this->harian();
        $pencairan = $this->c->deret($h['pencairan']);
        $pengeluaran = $this->c->deret($h['pengeluaran']);
        $selisih = [];
        $kumulatif = [];
        $jalan = 0;

        foreach ($pencairan as $i => $p) {
            $selisih[] = $p - $pengeluaran[$i];
            $jalan += $p - $pengeluaran[$i];
            $kumulatif[] = $jalan;
        }

        return $this->widget($this->c->periodeTren(), [
            'labels' => $this->c->hariTren(),
            'series' => [
                'pencairan' => $pencairan,
                'pengeluaran' => $pengeluaran,
                'selisih' => $selisih,
                'selisih_kumulatif' => $kumulatif,
            ],
            'total_selisih' => $jalan,
        ]);
    }

    /** @return list<int> Deret pengeluaran disetujui per hari tren (untuk widget tren investor). */
    public function deretPengeluaran(): array
    {
        return $this->c->deret($this->harian()['pengeluaran']);
    }
}
