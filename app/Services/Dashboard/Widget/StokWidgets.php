<?php

namespace App\Services\Dashboard\Widget;

use App\Enums\JenisMutasi;
use App\Enums\JenisPemasok;
use App\Enums\KategoriBahan;
use App\Enums\StatusPembelianBahan;
use App\Services\Dashboard\Cakupan;
use Illuminate\Database\Query\Builder;

/** Widget stok bahan baku dan mutasi gudang. */
final class StokWidgets
{
    use Format;

    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(private readonly Cakupan $c) {}

    private function hariPeringatan(): int
    {
        return max(0, (int) config('operasional.rekap.hari_peringatan_kedaluwarsa', 3));
    }

    /**
     * Hitungan stok kritis per SPPG dalam SATU query (dipakai juga widget lintas SPPG Admin).
     * Batas tanggal memakai pembanding setengah-terbuka agar benar untuk kolom DATE dan DATETIME.
     *
     * @return array<int, array{total: int, menipis: int, sudah_kedaluwarsa: int, akan_kedaluwarsa: int}>
     */
    public function perSppg(): array
    {
        return $this->memo['per_sppg'] ??= (function (): array {
            $hari = $this->c->hari();
            $batas = $this->c->hariIni->addDays($this->hariPeringatan() + 1)->toDateString();
            $hasil = [];

            foreach ($this->c->tabel('bahan_bakus')
                ->selectRaw(
                    'bahan_bakus.sppg_id as sppg_id, COUNT(*) as total, '
                    .'SUM(CASE WHEN bahan_bakus.stok <= bahan_bakus.stok_minimum THEN 1 ELSE 0 END) as menipis, '
                    .'SUM(CASE WHEN bahan_bakus.kedaluwarsa_terdekat IS NOT NULL AND bahan_bakus.kedaluwarsa_terdekat < ? THEN 1 ELSE 0 END) as sudah, '
                    .'SUM(CASE WHEN bahan_bakus.kedaluwarsa_terdekat IS NOT NULL AND bahan_bakus.kedaluwarsa_terdekat >= ? AND bahan_bakus.kedaluwarsa_terdekat < ? THEN 1 ELSE 0 END) as akan',
                    [$hari, $hari, $batas],
                )->groupBy('bahan_bakus.sppg_id')->get() as $r) {
                $hasil[(int) $r->sppg_id] = [
                    'total' => (int) $r->total,
                    'menipis' => (int) $r->menipis,
                    'sudah_kedaluwarsa' => (int) $r->sudah,
                    'akan_kedaluwarsa' => (int) $r->akan,
                ];
            }

            return $hasil;
        })();
    }

    /** @return array{total: int, menipis: int, sudah_kedaluwarsa: int, akan_kedaluwarsa: int} */
    public function total(): array
    {
        $t = ['total' => 0, 'menipis' => 0, 'sudah_kedaluwarsa' => 0, 'akan_kedaluwarsa' => 0];

        foreach ($this->perSppg() as $v) {
            foreach ($t as $k => $_) {
                $t[$k] += $v[$k];
            }
        }

        return $t;
    }

    /** @return array<string, mixed> Stok kritis (investor, kepala SPPG). */
    public function kritis(): array
    {
        $t = $this->total();

        return $this->widget($this->c->periodeSaatIni(), [
            'total_bahan' => $t['total'],
            'di_bawah_minimum' => $t['menipis'],
            'sudah_kedaluwarsa' => $t['sudah_kedaluwarsa'],
            'akan_kedaluwarsa' => $t['akan_kedaluwarsa'],
            'hari_peringatan_kedaluwarsa' => $this->hariPeringatan(),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function daftar(Builder $q, int $batas): array
    {
        return $q->limit($batas)->get(['bahan_bakus.id', 'bahan_bakus.nama', 'bahan_bakus.kategori', 'bahan_bakus.satuan', 'bahan_bakus.stok', 'bahan_bakus.stok_minimum', 'bahan_bakus.kedaluwarsa_terdekat'])
            ->map(fn ($r) => [
                'bahan_baku_id' => (int) $r->id,
                'nama' => (string) $r->nama,
                'kategori' => (string) $r->kategori,
                'satuan' => (string) $r->satuan,
                'stok' => round((float) $r->stok, 3),
                'stok_minimum' => round((float) $r->stok_minimum, 3),
                'kedaluwarsa_terdekat' => $r->kedaluwarsa_terdekat === null ? null : $this->hari($r->kedaluwarsa_terdekat),
            ])->all();
    }

    /** @return array<string, mixed> Stok per bahan: sebaran per kategori dan bahan dengan selisih terhadap minimum paling kecil. */
    public function perBahan(): array
    {
        $perKategori = [];

        foreach ($this->c->tabel('bahan_bakus')->selectRaw('kategori, COUNT(*) as jumlah')->groupBy('kategori')->get() as $r) {
            $perKategori[(string) $r->kategori] = (int) $r->jumlah;
        }

        $terendah = $this->daftar(
            $this->c->tabel('bahan_bakus')->orderByRaw('(bahan_bakus.stok - bahan_bakus.stok_minimum) asc')->orderBy('bahan_bakus.id'),
            15,
        );

        return $this->widget($this->c->periodeSaatIni(), [
            'total_bahan' => array_sum($perKategori),
            'per_kategori' => array_map(fn (KategoriBahan $k) => [
                'kategori' => $k->value,
                'label' => $k->label(),
                'jumlah' => $perKategori[$k->value] ?? 0,
            ], KategoriBahan::cases()),
            'terendah' => $terendah,
        ]);
    }

    /** @return array<string, mixed> */
    public function diBawahMinimum(): array
    {
        return $this->widget($this->c->periodeSaatIni(), [
            'jumlah' => $this->total()['menipis'],
            'items' => $this->daftar(
                $this->c->tabel('bahan_bakus')->whereColumn('bahan_bakus.stok', '<=', 'bahan_bakus.stok_minimum')
                    ->orderByRaw('(bahan_bakus.stok - bahan_bakus.stok_minimum) asc')->orderBy('bahan_bakus.id'),
                10,
            ),
        ]);
    }

    /** @return array<string, mixed> */
    public function mendekatiKedaluwarsa(): array
    {
        $t = $this->total();
        $batas = $this->c->hariIni->addDays($this->hariPeringatan() + 1)->toDateString();

        return $this->widget($this->c->periodeSaatIni(), [
            'sudah_kedaluwarsa' => $t['sudah_kedaluwarsa'],
            'akan_kedaluwarsa' => $t['akan_kedaluwarsa'],
            'hari_peringatan' => $this->hariPeringatan(),
            'items' => $this->daftar(
                $this->c->tabel('bahan_bakus')->whereNotNull('bahan_bakus.kedaluwarsa_terdekat')->where('bahan_bakus.kedaluwarsa_terdekat', '<', $batas)
                    ->orderBy('bahan_bakus.kedaluwarsa_terdekat')->orderBy('bahan_bakus.id'),
                10,
            ),
        ]);
    }

    /** @return array<string, mixed> Mutasi stok hari ini per jenis (jumlah kejadian; satuan bahan berbeda sehingga tidak dijumlahkan). */
    public function mutasiHariIni(): array
    {
        $per = [];

        foreach ($this->c->pada($this->c->tabel('mutasi_stoks'), 'mutasi_stoks.tanggal_mutasi')
            ->selectRaw('jenis, COUNT(*) as jumlah')->groupBy('jenis')->get() as $r) {
            $per[(string) $r->jenis] = (int) $r->jumlah;
        }

        return $this->widget($this->c->periodeHariIni(), [
            'total' => array_sum($per),
            'per_jenis' => array_map(fn (JenisMutasi $j) => ['jenis' => $j->value, 'jumlah' => $per[$j->value] ?? 0], JenisMutasi::cases()),
        ]);
    }

    /**
     * Penerimaan dari pemasok/koperasi (mutasi masuk bertanda pemasok) hari ini. `terjadwal` selalu null:
     * sistem belum menyimpan jadwal pengiriman pemasok, hanya penerimaan yang sudah dicatat.
     *
     * @return array<string, mixed>
     */
    public function pengirimanPemasok(bool $denganPembelian): array
    {
        $perJenis = [];

        foreach ($this->c->pada($this->c->tabel('mutasi_stoks'), 'mutasi_stoks.tanggal_mutasi')
            ->join('pemasoks', 'pemasoks.id', '=', 'mutasi_stoks.pemasok_id')
            ->where('mutasi_stoks.jenis', JenisMutasi::Masuk->value)
            ->selectRaw('pemasoks.jenis as jenis, COUNT(*) as penerimaan, COUNT(DISTINCT mutasi_stoks.pemasok_id) as pemasok')
            ->groupBy('pemasoks.jenis')->get() as $r) {
            $perJenis[(string) $r->jenis] = ['penerimaan' => (int) $r->penerimaan, 'pemasok' => (int) $r->pemasok];
        }

        $isi = [
            'penerimaan_hari_ini' => array_sum(array_column($perJenis, 'penerimaan')),
            'pemasok_hari_ini' => array_sum(array_column($perJenis, 'pemasok')),
            'per_jenis_pemasok' => array_map(fn (JenisPemasok $j) => [
                'jenis' => $j->value,
                'label' => $j->label(),
                'penerimaan' => $perJenis[$j->value]['penerimaan'] ?? 0,
                'pemasok' => $perJenis[$j->value]['pemasok'] ?? 0,
            ], JenisPemasok::cases()),
            'terjadwal' => null,
            'pembelian' => null,
        ];

        if ($denganPembelian) {
            $status = [];

            foreach ($this->c->tabel('pembelian_bahans')
                ->where(fn ($q) => $q->where('pembelian_bahans.status', StatusPembelianBahan::Diajukan->value)
                    ->orWhere(fn ($q2) => $q2->where('pembelian_bahans.status', StatusPembelianBahan::Ditolak->value)
                        ->where('pembelian_bahans.tanggal_beli', '>=', $this->c->awalBulan())))
                ->selectRaw('status, COUNT(*) as jumlah')->groupBy('status')->get() as $r) {
                $status[(string) $r->status] = (int) $r->jumlah;
            }

            $isi['pembelian'] = [
                'menunggu_verifikasi' => $status[StatusPembelianBahan::Diajukan->value] ?? 0,
                'ditolak_bulan_ini' => $status[StatusPembelianBahan::Ditolak->value] ?? 0,
            ];
        }

        return $this->widget($this->c->periodeHariIni(), $isi);
    }
}
