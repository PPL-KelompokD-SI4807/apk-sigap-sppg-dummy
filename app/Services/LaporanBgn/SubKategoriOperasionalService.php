<?php

namespace App\Services\LaporanBgn;

use App\Enums\KategoriPengeluaran;
use App\Enums\StatusPersetujuanPengeluaran;
use App\Enums\SubKategoriPengeluaran;
use App\Models\Pengeluaran;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ringkasan 17 baris Biaya Operasional laporan BGN (Lampiran 30b-B / 30d-B) + kelengkapan isian `sub_kategori`
 * (R07-5, dipakai tool Agen AI `lihat_sub_kategori_laporan_bgn`).
 *
 * - Nominal baris = RingkasanKeuanganService::realisasi() atas pengeluaran DISETUJUI pada rentang, sehingga angka
 *   sama persis dengan laporan (termasuk pemetaan data lama Q-5: tanpa sub_kategori -> Insentif Relawan / Lain-lain).
 * - Kelengkapan = pengeluaran operasional disetujui (bukan bahan_baku / insentif_fasilitas) yang belum diberi
 *   `sub_kategori`; yang jatuh ke "Lain-lain" karena pemetaan otomatis dihitung terpisah.
 * - `belum_diisi` (opsional, $rinci) = daftar pengeluaran operasional tanpa sub_kategori yang disetujui ATAU masih
 *   diajukan (akan masuk laporan bila disetujui), maks $batas baris. Hanya kolom minimal: id, tanggal, kategori,
 *   nominal, status, baris laporan saat ini, dan `uraian` MENTAH (teks bebas tak tepercaya, dapat memuat nama orang):
 *   pemanggil WAJIB menyamarkan/memotongnya sebelum ditampilkan atau dikirim ke LLM.
 * Pemanggil WAJIB sudah memeriksa hak (LaporanBgnPolicy::lihat) untuk $sppgId; query tetap memakai sppg_id eksplisit
 * dan SppgScope pengguna yang sedang bertindak.
 */
class SubKategoriOperasionalService
{
    public const BATAS_RINCI = 10;

    public function __construct(private readonly RingkasanKeuanganService $keuangan) {}

    /**
     * @return array{
     *     baris: list<array{sub_kategori: string, label: string, nominal: int, transaksi: int, dari_kategori_lama: int}>,
     *     total_operasional: int,
     *     kelengkapan: array{transaksi_operasional: int, sudah_diisi: int, belum_diisi: int, belum_diisi_masuk_lain_lain: int, nominal_belum_diisi: int, persen_terisi: int, status: string, diajukan_belum_diisi: int},
     *     belum_diisi?: list<array{id: int, tanggal: string, kategori: string, nominal: int, status_persetujuan: string, baris_laporan: string, uraian: string}>,
     *     belum_diisi_ada_lagi?: bool
     * }
     */
    public function ringkas(int $sppgId, CarbonInterface $awal, CarbonInterface $akhir, bool $rinci, int $batas = self::BATAS_RINCI): array
    {
        $disetujui = $this->keuangan->pengeluaran($sppgId, $awal, $akhir);
        $realisasi = $this->keuangan->realisasi($disetujui);
        $operasional = $disetujui->filter(fn (Pengeluaran $p) => RingkasanKeuanganService::kelompok($p) === RingkasanKeuanganService::OPS);

        $transaksi = array_fill_keys(SubKategoriPengeluaran::values(), 0);
        $lama = array_fill_keys(SubKategoriPengeluaran::values(), 0);
        $belum = 0;
        $belumLainLain = 0;
        $nominalBelum = 0;

        foreach ($operasional as $p) {
            [$sub] = RingkasanKeuanganService::subOperasional($p);
            $transaksi[$sub->value]++;

            if (! $p->sub_kategori instanceof SubKategoriPengeluaran) {
                $lama[$sub->value]++;
                $belum++;
                $nominalBelum += RingkasanKeuanganService::rupiah($p->nominal);
                $belumLainLain += $sub === SubKategoriPengeluaran::LainLain ? 1 : 0;
            }
        }

        $baris = [];

        foreach (SubKategoriPengeluaran::cases() as $sub) {
            $baris[] = [
                'sub_kategori' => $sub->value,
                'label' => $sub->label(),
                'nominal' => $realisasi['per_sub'][$sub->value]['jumlah'],
                'transaksi' => $transaksi[$sub->value],
                'dari_kategori_lama' => $lama[$sub->value],
            ];
        }

        $jumlah = $operasional->count();
        $diajukan = $this->tanpaSubKategori($sppgId, $awal, $akhir, [StatusPersetujuanPengeluaran::Diajukan])->count();

        $hasil = [
            'baris' => $baris,
            'total_operasional' => $realisasi['operasional'],
            'kelengkapan' => [
                'transaksi_operasional' => $jumlah,
                'sudah_diisi' => $jumlah - $belum,
                'belum_diisi' => $belum,
                'belum_diisi_masuk_lain_lain' => $belumLainLain,
                'nominal_belum_diisi' => $nominalBelum,
                'persen_terisi' => $jumlah === 0 ? 100 : intdiv(($jumlah - $belum) * 100, $jumlah),
                'status' => $belum === 0 ? 'lengkap' : 'belum_lengkap',
                'diajukan_belum_diisi' => $diajukan,
            ],
        ];

        if (! $rinci) {
            return $hasil;
        }

        $daftar = $this->tanpaSubKategori($sppgId, $awal, $akhir, [StatusPersetujuanPengeluaran::Disetujui, StatusPersetujuanPengeluaran::Diajukan])
            ->orderBy('tanggal')->orderBy('id')
            ->limit($batas + 1)
            ->get();

        $hasil['belum_diisi'] = $daftar->take($batas)->map(fn (Pengeluaran $p) => [
            'id' => (int) $p->getKey(),
            'tanggal' => (string) $p->tanggal?->toDateString(),
            'kategori' => (string) $p->kategori?->value,
            'nominal' => RingkasanKeuanganService::rupiah($p->nominal),
            'status_persetujuan' => (string) $p->status_persetujuan?->value,
            'baris_laporan' => RingkasanKeuanganService::subOperasional($p)[0]->label(),
            'uraian' => (string) $p->uraian,
        ])->values()->all();
        $hasil['belum_diisi_ada_lagi'] = $daftar->count() > $batas;

        return $hasil;
    }

    /**
     * Pengeluaran operasional (bukan bahan baku / insentif fasilitas, bukan hasil pembelian) tanpa sub_kategori.
     *
     * @param  list<StatusPersetujuanPengeluaran>  $status
     * @return Builder<Pengeluaran>
     */
    private function tanpaSubKategori(int $sppgId, CarbonInterface $awal, CarbonInterface $akhir, array $status): Builder
    {
        return Pengeluaran::query()
            ->where('sppg_id', $sppgId)
            ->whereNull('sub_kategori')
            ->whereNull('pembelian_bahan_id')
            ->whereNotIn('kategori', [KategoriPengeluaran::BahanBaku->value, KategoriPengeluaran::InsentifFasilitas->value])
            ->whereIn('status_persetujuan', array_map(fn (StatusPersetujuanPengeluaran $s) => $s->value, $status))
            ->whereDate('tanggal', '>=', $awal->toDateString())
            ->whereDate('tanggal', '<=', $akhir->toDateString());
    }
}
