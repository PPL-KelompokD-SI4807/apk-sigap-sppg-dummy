<?php

namespace App\Services\Dashboard\Widget;

use App\Enums\HasilMutu;
use App\Enums\StatusJadwalProduksi;
use App\Services\Dashboard\Cakupan;
use Illuminate\Database\Query\Builder;

/** Widget mutu dan keamanan pangan: hasil pemeriksaan, insiden (tidak layak), checklist yang belum diisi. */
final class MutuWidgets
{
    use Format;

    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(private readonly Cakupan $c) {}

    /**
     * Hitungan pemeriksaan per hari dan hasil untuk jendela terjauh (bulan berjalan dan tren).
     *
     * @return array<string, array<string, int>> hari => [hasil => jumlah]
     */
    private function perHari(): array
    {
        return $this->memo['hari'] ??= (function (): array {
            $hasil = [];

            foreach ($this->c->antara($this->c->tabel('pemeriksaan_mutus'), 'pemeriksaan_mutus.waktu_pemeriksaan', $this->c->mulaiTerjauh(), $this->c->hari())
                ->selectRaw('DATE(pemeriksaan_mutus.waktu_pemeriksaan) as hari, pemeriksaan_mutus.hasil as hasil, COUNT(*) as n')
                ->groupBy('hari', 'pemeriksaan_mutus.hasil')->get() as $r) {
                $hasil[$this->hari($r->hari)][(string) $r->hasil] = (int) $r->n;
            }

            return $hasil;
        })();
    }

    /**
     * @param  string  $dari  Y-m-d
     * @return array{pemeriksaan: int, layak: int, tidak_layak: int}
     */
    private function jumlah(string $dari, string $sampai): array
    {
        $layak = 0;
        $tidak = 0;

        foreach ($this->perHari() as $hari => $per) {
            if ($hari >= $dari && $hari <= $sampai) {
                $layak += $per[HasilMutu::Layak->value] ?? 0;
                $tidak += $per[HasilMutu::TidakLayak->value] ?? 0;
            }
        }

        return ['pemeriksaan' => $layak + $tidak, 'layak' => $layak, 'tidak_layak' => $tidak];
    }

    /** Jadwal produksi hari ini (tidak dibatalkan) yang belum punya pemeriksaan mutu. */
    private function belumDiperiksa(): Builder
    {
        return $this->c->pada($this->c->tabel('jadwal_produksis'), 'jadwal_produksis.tanggal_produksi')
            ->where('jadwal_produksis.status', '!=', StatusJadwalProduksi::Dibatalkan->value)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('pemeriksaan_mutus')
                ->whereColumn('pemeriksaan_mutus.jadwal_produksi_id', 'jadwal_produksis.id')
                ->whereNull('pemeriksaan_mutus.deleted_at'));
    }

    /** @return array<string, mixed> Ringkasan mutu (kepala SPPG, ahli gizi). */
    public function ringkas(): array
    {
        $hariIni = $this->jumlah($this->c->hari(), $this->c->hari());
        $bulan = $this->jumlah($this->c->awalBulan(), $this->c->hari());

        return $this->widget($this->c->periodeHariIni(), [
            'hari_ini' => $hariIni + ['batch_belum_diperiksa' => $this->belumDiperiksa()->count()],
            'bulan_berjalan' => $bulan + ['periode' => $this->c->periodeBulan()],
            'persen_layak_bulan' => $this->persen($bulan['layak'], $bulan['pemeriksaan']),
        ]);
    }

    /** @return array<string, mixed> Insiden mutu (pemeriksaan tidak layak) untuk investor. */
    public function insiden(): array
    {
        $tidak = [];

        foreach ($this->perHari() as $hari => $per) {
            $tidak[$hari] = $per[HasilMutu::TidakLayak->value] ?? 0;
        }

        return $this->widget($this->c->periodeBulan(), [
            'tidak_layak_hari_ini' => $this->jumlah($this->c->hari(), $this->c->hari())['tidak_layak'],
            'tidak_layak_bulan' => $this->jumlah($this->c->awalBulan(), $this->c->hari())['tidak_layak'],
            'tren' => [
                'periode' => $this->c->periodeTren(),
                'labels' => $this->c->hariTren(),
                'series' => ['tidak_layak' => $this->c->deret($tidak)],
            ],
        ]);
    }

    /** @return list<int> Deret pemeriksaan tidak layak per hari pada jendela tren (untuk widget tren investor). */
    public function deretTidakLayak(): array
    {
        $tidak = [];

        foreach ($this->perHari() as $hari => $per) {
            $tidak[$hari] = $per[HasilMutu::TidakLayak->value] ?? 0;
        }

        return $this->c->deret($tidak);
    }

    /** @return array<string, mixed> Checklist mutu hari ini yang belum diisi (relawan dapur). */
    public function checklistBelumDiisi(): array
    {
        $items = $this->belumDiperiksa()
            ->join('menus', 'menus.id', '=', 'jadwal_produksis.menu_id')
            ->orderBy('jadwal_produksis.jam_mulai_masak')->orderBy('jadwal_produksis.id')->limit(30)
            ->get(['jadwal_produksis.id as jadwal_id', 'menus.nama_menu', 'jadwal_produksis.jam_mulai_masak', 'jadwal_produksis.status'])
            ->map(fn ($r) => [
                'jadwal_id' => (int) $r->jadwal_id,
                'menu' => (string) $r->nama_menu,
                'jam_mulai_masak' => $r->jam_mulai_masak === null ? null : substr((string) $r->jam_mulai_masak, 0, 5),
                'status' => (string) $r->status,
            ])->all();

        return $this->widget($this->c->periodeHariIni(), [
            'jumlah' => $this->belumDiperiksa()->count(),
            'items' => $items,
        ]);
    }
}
