<?php

namespace App\Services\LaporanBgn;

use App\Enums\KategoriPengeluaran;
use App\Enums\StatusPembelianBahan;
use App\Enums\SubKategoriPengeluaran;
use App\Models\PembelianBahan;
use App\Models\PencairanDana;
use App\Models\Pengeluaran;
use App\Models\Sppg;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Agregasi keuangan Lampiran 30b/30c/30d. Definisi (konsisten dengan BudgetService/DailySummaryService):
 *  - Dana masuk = pencairan_danas.nominal menurut tanggal_cair.
 *  - Dana keluar = pengeluarans berstatus `disetujui` menurut tanggal (yang diajukan/ditolak tidak dihitung).
 *  - Tiga kelompok BGN: Bahan Baku = kategori bahan_baku; Insentif Fasilitas SPPG = kategori insentif_fasilitas;
 *    Operasional = sisanya (Q-5: data lama tanpa sub_kategori: insentif_relawan -> Insentif Relawan; energi,
 *    transportasi, peralatan, lainnya -> Lain-lain dengan keterangan kategori lama, tanpa tebakan Listrik/Gas/BBM).
 *  - Saldo awal periode (Q-2): sppgs.saldo_awal (diisi Akuntan) + pencairan - pengeluaran disetujui sejak
 *    saldo_awal_tanggal sampai sehari sebelum periode; periode berikutnya otomatis = saldo akhir sebelumnya.
 *  - Bahan baku TIDAK dihitung ganda: total dari pengeluaran; rincian dari pembelian terverifikasi yang pengeluaran
 *    terkaitnya disetujui; selisih (bahan baku tanpa pembelian atau nominal berbeda) = "Bahan baku lainnya".
 * Semua nominal rupiah bulat (int).
 */
class RingkasanKeuanganService
{
    public const BB = 'bahan_baku';

    public const OPS = 'operasional';

    public const INSENTIF = 'insentif_fasilitas';

    /**
     * @return Collection<int, Pengeluaran> pengeluaran disetujui pada rentang, urut tanggal lalu id
     */
    public function pengeluaran(int $sppgId, CarbonInterface $awal, CarbonInterface $akhir): Collection
    {
        return Pengeluaran::query()->disetujui()
            ->where('sppg_id', $sppgId)
            ->whereDate('tanggal', '>=', $awal->toDateString())
            ->whereDate('tanggal', '<=', $akhir->toDateString())
            ->orderBy('tanggal')->orderBy('id')
            ->get();
    }

    /** @return Collection<int, PencairanDana> */
    public function pencairan(int $sppgId, CarbonInterface $awal, CarbonInterface $akhir): Collection
    {
        return PencairanDana::query()
            ->where('sppg_id', $sppgId)
            ->whereDate('tanggal_cair', '>=', $awal->toDateString())
            ->whereDate('tanggal_cair', '<=', $akhir->toDateString())
            ->orderBy('tanggal_cair')->orderBy('id')
            ->get();
    }

    public static function rupiah(mixed $nilai): int
    {
        return (int) round((float) $nilai);
    }

    public static function kelompok(Pengeluaran $p): string
    {
        return match ($p->kategori) {
            KategoriPengeluaran::BahanBaku => self::BB,
            KategoriPengeluaran::InsentifFasilitas => self::INSENTIF,
            default => self::OPS,
        };
    }

    /**
     * Baris operasional (17 baku) dan keterangan untuk satu pengeluaran operasional.
     *
     * @return array{0: SubKategoriPengeluaran, 1: string|null}
     */
    public static function subOperasional(Pengeluaran $p): array
    {
        if ($p->sub_kategori instanceof SubKategoriPengeluaran) {
            return [$p->sub_kategori, null];
        }

        return match ($p->kategori) {
            KategoriPengeluaran::InsentifRelawan => [SubKategoriPengeluaran::InsentifRelawan, null],
            default => [SubKategoriPengeluaran::LainLain, $p->kategori?->label()],
        };
    }

    /**
     * Total per kelompok + rincian operasional per sub-kategori dari kumpulan pengeluaran.
     *
     * @param  Collection<int, Pengeluaran>  $pengeluaran
     * @return array{bahan_baku: int, operasional: int, insentif_fasilitas: int, total: int, per_sub: array<string, array{jumlah: int, keterangan: list<string>}>, jumlah_transaksi: array{bahan_baku: int, operasional: int, insentif_fasilitas: int}}
     */
    public function realisasi(Collection $pengeluaran): array
    {
        $hasil = [self::BB => 0, self::OPS => 0, self::INSENTIF => 0];
        $transaksi = [self::BB => 0, self::OPS => 0, self::INSENTIF => 0];
        $perSub = [];

        foreach (SubKategoriPengeluaran::cases() as $sub) {
            $perSub[$sub->value] = ['jumlah' => 0, 'keterangan' => []];
        }

        foreach ($pengeluaran as $p) {
            $nominal = self::rupiah($p->nominal);
            $kelompok = self::kelompok($p);
            $hasil[$kelompok] += $nominal;
            $transaksi[$kelompok]++;

            if ($kelompok === self::OPS) {
                [$sub, $keterangan] = self::subOperasional($p);
                $perSub[$sub->value]['jumlah'] += $nominal;

                if ($keterangan !== null && ! in_array($keterangan, $perSub[$sub->value]['keterangan'], true)) {
                    $perSub[$sub->value]['keterangan'][] = $keterangan;
                }
            }
        }

        return [
            'bahan_baku' => $hasil[self::BB],
            'operasional' => $hasil[self::OPS],
            'insentif_fasilitas' => $hasil[self::INSENTIF],
            'total' => array_sum($hasil),
            'per_sub' => $perSub,
            'jumlah_transaksi' => ['bahan_baku' => $transaksi[self::BB], 'operasional' => $transaksi[self::OPS], 'insentif_fasilitas' => $transaksi[self::INSENTIF]],
        ];
    }

    /**
     * Saldo awal periode. Bila saldo awal belum diisi: 0 dan peringatan (R07 K3); transaksi sebelum periode tetap
     * dihitung dari awal data. Periode yang dimulai sebelum tanggal saldo awal: saldo awal tidak dipakai (peringatan).
     *
     * @return array{saldo: int, saldo_awal_terisi: bool, peringatan: string|null}
     */
    public function saldoAwalPeriode(Sppg $sppg, CarbonInterface $awalPeriode): array
    {
        $kemarin = $awalPeriode->copy()->subDay();
        $diisi = $sppg->saldo_awal !== null && $sppg->saldo_awal_tanggal !== null;

        if (! $diisi) {
            return ['saldo' => $this->mutasi((int) $sppg->getKey(), null, $kemarin), 'saldo_awal_terisi' => false, 'peringatan' => 'saldo_awal_belum_diisi'];
        }

        $tanggalSaldo = $sppg->saldo_awal_tanggal;

        if ($awalPeriode->toDateString() < $tanggalSaldo->toDateString()) {
            return ['saldo' => 0, 'saldo_awal_terisi' => true, 'peringatan' => 'periode_sebelum_saldo_awal'];
        }

        return [
            'saldo' => self::rupiah($sppg->saldo_awal) + $this->mutasi((int) $sppg->getKey(), $tanggalSaldo, $kemarin),
            'saldo_awal_terisi' => true,
            'peringatan' => null,
        ];
    }

    /**
     * Rincian bahan baku pangan satu rentang (30b-A): satu baris per (bahan, satuan, harga satuan) dari pembelian
     * terverifikasi yang pengeluarannya disetujui, ditambah "Bahan baku lainnya" (selisih terhadap total pengeluaran).
     *
     * @param  Collection<int, Pengeluaran>  $pengeluaran  pengeluaran disetujui rentang yang sama
     * @return array{rincian: list<array{nama: string, jumlah: string, satuan: string, harga_satuan: int, total: int}>, bahan_baku_lainnya: int, total: int}
     */
    public function rincianBahanBaku(Collection $pengeluaran): array
    {
        $bb = $pengeluaran->filter(fn (Pengeluaran $p) => self::kelompok($p) === self::BB);
        $total = $bb->sum(fn (Pengeluaran $p) => self::rupiah($p->nominal));
        $pembelianIds = $bb->pluck('pembelian_bahan_id')->filter()->map(fn ($id) => (int) $id)->all();

        $baris = [];
        $terinci = 0;

        if ($pembelianIds !== []) {
            $pembelian = PembelianBahan::query()->with('bahanBaku')
                ->whereIn('id', $pembelianIds)->where('status', StatusPembelianBahan::Terverifikasi->value)
                ->get();

            foreach ($pembelian as $b) {
                $harga = self::rupiah($b->harga_satuan);
                $kunci = ($b->bahanBaku?->nama ?? 'Bahan baku').'|'.$b->satuan.'|'.$harga;
                $baris[$kunci] ??= ['nama' => (string) ($b->bahanBaku?->nama ?? 'Bahan baku'), 'jumlah' => '0', 'satuan' => (string) $b->satuan, 'harga_satuan' => $harga, 'total' => 0];
                $baris[$kunci]['jumlah'] = bcadd($baris[$kunci]['jumlah'], (string) $b->jumlah, 3);
                $baris[$kunci]['total'] += self::rupiah($b->total);
                $terinci += self::rupiah($b->total);
            }
        }

        ksort($baris);

        return ['rincian' => array_values($baris), 'bahan_baku_lainnya' => $total - $terinci, 'total' => $total];
    }

    /** Pencairan dikurangi pengeluaran disetujui pada rentang [dari, sampai]; dari = null = sejak awal data. */
    private function mutasi(int $sppgId, ?CarbonInterface $dari, CarbonInterface $sampai): int
    {
        $masuk = PencairanDana::query()->where('sppg_id', $sppgId)->whereDate('tanggal_cair', '<=', $sampai->toDateString());
        $keluar = Pengeluaran::query()->disetujui()->where('sppg_id', $sppgId)->whereDate('tanggal', '<=', $sampai->toDateString());

        if ($dari !== null) {
            $masuk->whereDate('tanggal_cair', '>=', $dari->toDateString());
            $keluar->whereDate('tanggal', '>=', $dari->toDateString());
        }

        return self::rupiah($masuk->sum('nominal')) - self::rupiah($keluar->sum('nominal'));
    }
}
