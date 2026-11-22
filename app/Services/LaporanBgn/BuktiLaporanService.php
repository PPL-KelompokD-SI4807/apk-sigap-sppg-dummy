<?php

namespace App\Services\LaporanBgn;

use App\Enums\JenisLaporanBgn;
use App\Models\Lampiran;
use App\Models\PembelianBahan;
use App\Models\Pengeluaran;
use App\Models\Sppg;
use App\Models\User;
use App\Services\LaporanBgn\Pdf\Teks;
use App\Services\Storage\SecureUploadService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Foto nota untuk halaman lampiran bukti PDF (R07-3), terpisah dari halaman format BGN.
 *
 * - Hanya 30b dan 30d-B (laporan yang memuat transaksi). Investor tidak pernah mendapat lampiran (nota dapat memuat
 *   nama/data pribadi; modul pembelian-bahan menyembunyikannya dari investor).
 * - Sumber: lampiran pengeluaran disetujui pada periode; bila kosong, lampiran nota pembelian bahan terkait.
 *   Satu foto per pengeluaran, urut mengikuti nomor baris 30d-B (atau urutan tanggal untuk 30b).
 * - Batas config('laporan-bgn.maks_bukti'); foto diperkecil dengan GD (bukti_maks_sisi_px, JPEG) satu per satu
 *   agar memori puncak terkendali.
 */
class BuktiLaporanService
{
    public function __construct(
        private readonly RingkasanKeuanganService $keuangan,
        private readonly SecureUploadService $upload,
    ) {}

    /**
     * @return array{items: list<array{label: string, lampiran: Lampiran}>, dilewati: int}
     */
    public function kumpulkan(JenisLaporanBgn $jenis, Sppg $sppg, CarbonImmutable $awal, CarbonImmutable $akhir, User $pengguna): array
    {
        if ($pengguna->isInvestor() || ! in_array($jenis, [JenisLaporanBgn::Harian, JenisLaporanBgn::BulananAnggaran], true)) {
            return ['items' => [], 'dilewati' => 0];
        }

        $sppgId = (int) $sppg->getKey();
        $pengeluaran = $this->keuangan->pengeluaran($sppgId, $awal, $akhir);

        if ($pengeluaran->isEmpty()) {
            return ['items' => [], 'dilewati' => 0];
        }

        $nomorBaris = $jenis === JenisLaporanBgn::BulananAnggaran ? $this->nomorBaris($sppgId, $awal, $akhir, $pengeluaran) : [];
        $lampiran = $this->lampiranPengeluaran($sppgId, $pengeluaran);
        $items = [];
        $urut = 0;

        $daftar = $pengeluaran->all();

        if ($jenis === JenisLaporanBgn::BulananAnggaran) {
            usort($daftar, fn (Pengeluaran $a, Pengeluaran $b) => ($nomorBaris[$a->getKey()] ?? PHP_INT_MAX) <=> ($nomorBaris[$b->getKey()] ?? PHP_INT_MAX));
        }

        foreach ($daftar as $p) {
            $l = $lampiran[$p->getKey()] ?? null;

            if ($l === null) {
                continue;
            }

            $urut++;
            $kelompok = match (RingkasanKeuanganService::kelompok($p)) {
                RingkasanKeuanganService::BB => 'Bahan Baku Pangan',
                RingkasanKeuanganService::INSENTIF => 'Insentif Fasilitas SPPG',
                default => 'Biaya Operasional',
            };
            $awalan = isset($nomorBaris[$p->getKey()]) ? 'Baris '.$nomorBaris[$p->getKey()].' - ' : '';
            $items[] = [
                'label' => $awalan.Teks::tanggalPendek($p->tanggal->toDateString()).' - '.$kelompok.' - '.Teks::rupiah(RingkasanKeuanganService::rupiah($p->nominal)),
                'lampiran' => $l,
            ];
        }

        $maks = max(0, (int) config('laporan-bgn.maks_bukti', 20));

        return ['items' => array_slice($items, 0, $maks), 'dilewati' => max(0, count($items) - $maks)];
    }

    /** JPEG diperkecil (null bila berkas hilang/rusak/terlalu besar). */
    public function jpeg(Lampiran $lampiran): ?string
    {
        $isi = $this->upload->baca($lampiran);

        return $isi === null ? null : $this->perkecil($isi);
    }

    /** Perkecil gambar masuk (JPEG/PNG/WebP hasil unggahan aman) menjadi JPEG kecil; null bila rusak atau terlalu besar. */
    public function perkecil(string $isi): ?string
    {
        $info = @getimagesizefromstring($isi);

        if ($info === false || $info[0] < 1 || $info[1] < 1 || (int) config('laporan-bgn.bukti_maks_piksel', 4_000_000) < $info[0] * $info[1]) {
            return null;
        }

        $gambar = @imagecreatefromstring($isi);
        unset($isi);

        if ($gambar === false) {
            return null;
        }

        $maks = max(200, (int) config('laporan-bgn.bukti_maks_sisi_px', 900));
        $skala = min(1.0, $maks / max($info[0], $info[1]));
        $w = max(1, (int) round($info[0] * $skala));
        $h = max(1, (int) round($info[1] * $skala));
        $kanvas = imagecreatetruecolor($w, $h);
        imagefill($kanvas, 0, 0, (int) imagecolorallocate($kanvas, 255, 255, 255));
        imagecopyresampled($kanvas, $gambar, 0, 0, 0, 0, $w, $h, $info[0], $info[1]);
        imagedestroy($gambar);

        ob_start();
        imagejpeg($kanvas, null, max(30, min(95, (int) config('laporan-bgn.bukti_kualitas_jpeg', 75))));
        $hasil = (string) ob_get_clean();
        imagedestroy($kanvas);

        return $hasil === '' ? null : $hasil;
    }

    /**
     * Nomor baris 30d-B per pengeluaran: 1 = saldo awal, lalu pencairan, lalu pengeluaran per subkelompok
     * (bahan baku, operasional, insentif fasilitas) urut tanggal/id. Harus identik dengan LaporanBgnService::anggaran().
     *
     * @param  Collection<int, Pengeluaran>  $pengeluaran
     * @return array<int, int> id pengeluaran => nomor baris
     */
    private function nomorBaris(int $sppgId, CarbonImmutable $awal, CarbonImmutable $akhir, Collection $pengeluaran): array
    {
        $no = 1 + $this->keuangan->pencairan($sppgId, $awal, $akhir)->count();
        $peta = [];

        foreach ([RingkasanKeuanganService::BB, RingkasanKeuanganService::OPS, RingkasanKeuanganService::INSENTIF] as $kelompok) {
            foreach ($pengeluaran as $p) {
                if (RingkasanKeuanganService::kelompok($p) === $kelompok) {
                    $peta[(int) $p->getKey()] = ++$no;
                }
            }
        }

        return $peta;
    }

    /**
     * @param  Collection<int, Pengeluaran>  $pengeluaran
     * @return array<int, Lampiran> id pengeluaran => foto nota pertama
     */
    private function lampiranPengeluaran(int $sppgId, Collection $pengeluaran): array
    {
        $tipeP = (new Pengeluaran)->getMorphClass();
        $tipeB = (new PembelianBahan)->getMorphClass();
        $idP = $pengeluaran->pluck('id')->map(fn ($i) => (int) $i)->all();
        $pembelian = $pengeluaran->pluck('pembelian_bahan_id', 'id')->filter()->map(fn ($i) => (int) $i)->all();
        $mimeGambar = array_keys((array) config('unggahan.mime', []));

        $semua = Lampiran::query()->withoutGlobalScopes()
            ->where('sppg_id', $sppgId)->whereIn('mime', $mimeGambar)
            ->where(function ($q) use ($tipeP, $idP, $tipeB, $pembelian): void {
                $q->where(fn ($w) => $w->where('lampiranable_type', $tipeP)->whereIn('lampiranable_id', $idP));

                if ($pembelian !== []) {
                    $q->orWhere(fn ($w) => $w->where('lampiranable_type', $tipeB)->whereIn('lampiranable_id', array_values($pembelian)));
                }
            })
            ->orderBy('id')->get();

        $hasil = [];

        foreach ($pengeluaran as $p) {
            $punya = $semua->first(fn (Lampiran $l) => $l->lampiranable_type === $tipeP && (int) $l->lampiranable_id === (int) $p->getKey())
                ?? (isset($pembelian[$p->getKey()])
                    ? $semua->first(fn (Lampiran $l) => $l->lampiranable_type === $tipeB && (int) $l->lampiranable_id === $pembelian[$p->getKey()])
                    : null);

            if ($punya !== null) {
                $hasil[(int) $p->getKey()] = $punya;
            }
        }

        return $hasil;
    }
}
