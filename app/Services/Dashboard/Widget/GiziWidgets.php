<?php

namespace App\Services\Dashboard\Widget;

use App\Enums\KelompokSasaran;
use App\Enums\StatusJadwalProduksi;
use App\Enums\StatusPersetujuanMenu;
use App\Services\Dashboard\Cakupan;
use App\Services\Gizi\GiziService;
use App\Services\PenerimaManfaat\AgregatPenerimaService;

/**
 * Widget ahli gizi: menu hari ini, gizi vs standar, biaya per porsi, kebutuhan khusus (agregat), tanpa nama/NIK.
 *
 * Kebutuhan khusus: sistem belum punya kolom terstruktur; indikator yang dipakai ialah penerima aktif yang
 * `catatan_alergi`-nya terisi. Hanya SATU agregat yang ditampilkan (sel per kelompok sasaran); tidak ada total
 * penerima aktif atau pelengkapnya, sehingga selisih antar-agregat tidak dapat membuka sel kecil.
 */
final class GiziWidgets
{
    use Format;

    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(private readonly Cakupan $c) {}

    /** @return list<object> menu yang disajikan hari ini atau dijadwalkan diproduksi hari ini (maks. 30) */
    private function menuHariIni(): array
    {
        return $this->memo['menu'] ??= $this->c->tabel('menus')
            ->where(function ($q): void {
                $q->where(fn ($w) => $this->c->pada($w, 'menus.tanggal_sajian'))
                    ->orWhereIn('menus.id', function ($sub): void {
                        $sub->select('jadwal_produksis.menu_id')->from('jadwal_produksis')
                            ->whereIn('jadwal_produksis.sppg_id', $this->c->ids)->whereNull('jadwal_produksis.deleted_at')
                            ->where('jadwal_produksis.status', '!=', StatusJadwalProduksi::Dibatalkan->value);
                        $this->c->pada($sub, 'jadwal_produksis.tanggal_produksi');
                    });
            })
            ->orderBy('menus.nama_menu')->orderBy('menus.id')->limit(30)
            ->get(['menus.id', 'menus.sppg_id', 'menus.nama_menu', 'menus.kelompok_sasaran', 'menus.status_persetujuan', 'menus.versi',
                'menus.energi_kkal', 'menus.protein_g', 'menus.lemak_g', 'menus.karbohidrat_g', 'menus.serat_g'])->all();
    }

    /** @return array<string, mixed> */
    public function menu(): array
    {
        $menu = $this->menuHariIni();
        $per = [];

        foreach ($menu as $m) {
            $per[(string) $m->status_persetujuan] = ($per[(string) $m->status_persetujuan] ?? 0) + 1;
        }

        return $this->widget($this->c->periodeHariIni(), [
            'jumlah' => count($menu),
            'per_status' => array_map(fn (StatusPersetujuanMenu $s) => ['status' => $s->value, 'label' => $s->label(), 'jumlah' => $per[$s->value] ?? 0], StatusPersetujuanMenu::cases()),
            'items' => array_map(fn ($m) => [
                'menu_id' => (int) $m->id,
                'nama_menu' => (string) $m->nama_menu,
                'kelompok_sasaran' => (string) $m->kelompok_sasaran,
                'status_persetujuan' => (string) $m->status_persetujuan,
                'status_label' => StatusPersetujuanMenu::tryFrom((string) $m->status_persetujuan)?->label(),
            ], $menu),
        ]);
    }

    /** @return array<string, mixed> Gizi menu hari ini terhadap rentang standar kelompok sasarannya. */
    public function giziVsStandar(): array
    {
        $menu = $this->menuHariIni();
        $standar = [];

        if ($menu !== []) {
            foreach ($this->c->tabel('standar_gizis')->get() as $s) {
                $standar[(int) $s->sppg_id.'|'.$s->kelompok_sasaran] = $s;
            }
        }

        $items = [];
        $sesuai = 0;
        $tidak = 0;
        $tanpa = 0;

        foreach ($menu as $m) {
            $s = $standar[(int) $m->sppg_id.'|'.$m->kelompok_sasaran] ?? null;
            $zat = [];
            $ok = $s !== null;

            foreach (GiziService::KOLOM as $kolom => [$label, $satuan, $awalan]) {
                $nilai = round((float) $m->{$kolom}, 2);
                $min = $s === null ? null : round((float) $s->{$awalan.'_min'}, 2);
                $max = $s === null ? null : round((float) $s->{$awalan.'_max'}, 2);
                $status = $s === null ? null : ($nilai < $min ? 'kurang' : ($nilai > $max ? 'lebih' : 'sesuai'));
                $ok = $ok && $status === 'sesuai';
                $zat[] = ['kolom' => $kolom, 'label' => $label, 'satuan' => $satuan, 'nilai' => $nilai, 'minimum' => $min, 'maksimum' => $max, 'status' => $status];
            }

            $s === null ? $tanpa++ : ($ok ? $sesuai++ : $tidak++);
            $items[] = ['menu_id' => (int) $m->id, 'nama_menu' => (string) $m->nama_menu, 'standar_tersedia' => $s !== null, 'sesuai' => $s === null ? null : $ok, 'zat' => $zat];
        }

        return $this->widget($this->c->periodeHariIni(), [
            'sesuai' => $sesuai,
            'tidak_sesuai' => $tidak,
            'tanpa_standar' => $tanpa,
            'items' => $items,
        ]);
    }

    /**
     * Biaya per porsi = snapshot pada pengajuan menu terakhir (tidak dibatalkan). Menu yang belum pernah diajukan
     * ke investor bernilai null (rincian biaya penuh tersedia di GET /menu-gizi/{id}/rincian-biaya).
     *
     * @return array<string, mixed>
     */
    public function biayaPerPorsi(): array
    {
        $menu = $this->menuHariIni();
        $terakhir = [];

        if ($menu !== []) {
            foreach ($this->c->tabel('pengajuan_menus', false)->whereIn('pengajuan_menus.menu_id', array_map(fn ($m) => (int) $m->id, $menu))
                ->where('pengajuan_menus.status', '!=', 'dibatalkan')
                ->orderByDesc('pengajuan_menus.nomor')
                ->get(['pengajuan_menus.menu_id', 'pengajuan_menus.nomor', 'pengajuan_menus.status', 'pengajuan_menus.biaya_per_porsi']) as $p) {
                $terakhir[(int) $p->menu_id] ??= $p;
            }
        }

        $items = array_map(function ($m) use ($terakhir): array {
            $p = $terakhir[(int) $m->id] ?? null;

            return [
                'menu_id' => (int) $m->id,
                'nama_menu' => (string) $m->nama_menu,
                'biaya_per_porsi' => $p === null ? null : round((float) $p->biaya_per_porsi, 2),
                'sumber' => $p === null ? null : 'pengajuan_terakhir',
                'nomor_pengajuan' => $p === null ? null : (int) $p->nomor,
                'status_pengajuan' => $p === null ? null : (string) $p->status,
            ];
        }, $menu);
        $nilai = array_values(array_filter(array_column($items, 'biaya_per_porsi'), fn ($v) => $v !== null));

        return $this->widget($this->c->periodeHariIni(), [
            'rata_rata' => $nilai === [] ? null : round(array_sum($nilai) / count($nilai), 2),
            'menu_tanpa_biaya' => count($items) - count($nilai),
            'items' => $items,
        ]);
    }

    /** @return array<string, mixed> Agregat kebutuhan khusus dengan penyamaran sel kecil (k dari config/rbac.php). */
    public function kebutuhanKhusus(): array
    {
        $k = AgregatPenerimaService::ambang();
        $per = [];

        foreach ($this->c->tabel('penerima_manfaats')->where('penerima_manfaats.is_active', true)
            ->whereNotNull('penerima_manfaats.catatan_alergi')->where('penerima_manfaats.catatan_alergi', '<>', '')
            ->selectRaw('penerima_manfaats.kelompok_sasaran as kelompok, COUNT(*) as jumlah')
            ->groupBy('penerima_manfaats.kelompok_sasaran')->get() as $r) {
            $per[(string) $r->kelompok] = (int) $r->jumlah;
        }

        $kelompok = KelompokSasaran::cases();
        $nilai = array_map(fn (KelompokSasaran $s) => $per[$s->value] ?? 0, $kelompok);
        [$topeng] = AgregatPenerimaService::topengTabel($nilai, $k, false);
        $adaTersamar = in_array(true, $topeng, true);
        $penanda = '<'.$k;

        $sel = [];

        foreach ($kelompok as $i => $s) {
            $sel[] = [
                'kelompok_sasaran' => $s->value,
                'label' => $s->label(),
                'jumlah' => $topeng[$i] ? null : $nilai[$i],
                'penanda' => $topeng[$i] ? $penanda : null,
            ];
        }

        return $this->widget($this->c->periodeSaatIni(), [
            'sumber' => 'catatan_alergi',
            'keterangan' => 'Penerima aktif dengan catatan alergi/kebutuhan khusus terisi. Sel bertanda disamarkan (bukan selalu kurang dari ambang).',
            'total' => $adaTersamar ? null : array_sum($nilai),
            'per_kelompok_sasaran' => $sel,
            'penyamaran' => ['diterapkan' => true, 'ambang_k' => $k, 'penanda' => $penanda],
        ]);
    }
}
