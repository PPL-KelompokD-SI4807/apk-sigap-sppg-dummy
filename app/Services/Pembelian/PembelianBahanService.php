<?php

namespace App\Services\Pembelian;

use App\Enums\KategoriPengeluaran;
use App\Enums\StatusPembelianBahan;
use App\Enums\StatusPersetujuanPengeluaran;
use App\Models\BahanBaku;
use App\Models\HargaPemasok;
use App\Models\PembelianBahan;
use App\Models\Pengeluaran;
use App\Models\Scopes\SppgScope;
use App\Models\User;
use App\Services\Auth\GalatAuth;
use App\Services\Keuangan\BudgetService;
use App\Services\Storage\PengikatLampiran;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Pembelian bahan (R-06b-2): staf gudang mencatat, akuntan memverifikasi.
 *
 * - `total` = round(jumlah x harga_satuan) rupiah bulat, dihitung server dengan bcmath (tanpa float).
 *   `satuan` disalin dari bahan baku; klien tidak pernah mengirim total/satuan/status.
 * - Urutan kunci baris: sppgs (mutex BudgetService::kunciSppg) -> pembelian_bahans -> pengeluarans.
 *   Ubah oleh staf gudang hanya mengunci baris pembelian (tidak pernah mengunci sppgs setelahnya).
 * - Verifikasi setuju membuat pengeluaran `bahan_baku` berstatus `disetujui` (tanpa investor) lalu
 *   mengevaluasi ambang anggaran 80/100%. Tolak: alasan wajib (divalidasi Form Request).
 * - Tidak membuat mutasi stok maupun entri harga pemasok (di luar lingkup).
 */
class PembelianBahanService
{
    public const ALASAN_VERSI = 'versi_tidak_cocok';

    public const ALASAN_STATUS = 'status_tidak_valid';

    public const ALASAN_SUDAH_DIVERIFIKASI = 'pembelian_sudah_diverifikasi';

    public function __construct(
        private readonly BudgetService $budget,
        private readonly PengikatLampiran $lampiran,
    ) {}

    /**
     * @param  array<string, mixed>  $data  hasil validasi StorePembelianBahanRequest
     */
    public function catat(User $pencatat, array $data): PembelianBahan
    {
        return DB::transaction(function () use ($pencatat, $data): PembelianBahan {
            $bahan = $this->bahanSppg((int) $data['bahan_baku_id'], (int) $pencatat->sppg_id);

            $pembelian = new PembelianBahan;
            $pembelian->forceFill([
                'sppg_id' => $pencatat->sppg_id,
                'dicatat_oleh' => $pencatat->getKey(),
                'status' => StatusPembelianBahan::Diajukan,
                'versi' => 1,
                'satuan' => $bahan->satuan,
            ]);
            $pembelian->fill(array_intersect_key($data, array_flip(['bahan_baku_id', 'pemasok_id', 'tanggal_beli', 'jumlah', 'harga_satuan', 'catatan'])));
            $total = self::hitungTotal((string) $pembelian->getAttributes()['jumlah'], (string) $pembelian->getAttributes()['harga_satuan']);
            self::pastikanTotalMuat($total);
            $pembelian->forceFill(['total' => $total]);
            $pembelian->save();

            $this->ikatNota($pembelian, $data['lampiran_ids'] ?? [], $pencatat);

            return $pembelian;
        });
    }

    /**
     * Ubah pembelian oleh staf gudang (status diajukan atau ditolak). Versi naik; ditolak -> diajukan lagi.
     *
     * @param  array<string, mixed>  $data  hasil validasi UpdatePembelianBahanRequest
     */
    public function ubah(User $pengguna, PembelianBahan $pembelian, array $data, int $versi): PembelianBahan
    {
        return DB::transaction(function () use ($pengguna, $pembelian, $data, $versi): PembelianBahan {
            $kunci = PembelianBahan::withoutGlobalScope(SppgScope::class)->whereKey($pembelian->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $kunci->sppg_id !== (int) $pengguna->sppg_id) {
                abort(404);
            }

            if (! $kunci->status->bisaDiubah()) {
                throw GalatAuth::respons(409, self::ALASAN_SUDAH_DIVERIFIKASI, 'Pembelian sudah diverifikasi dan tidak dapat diubah.');
            }

            if ((int) $kunci->versi !== $versi) {
                throw GalatAuth::respons(409, self::ALASAN_VERSI, 'Pembelian sudah berubah. Muat ulang lalu ubah kembali.', tambahan: ['versi_server' => (int) $kunci->versi]);
            }

            $kunci->fill(array_intersect_key($data, array_flip(['bahan_baku_id', 'pemasok_id', 'tanggal_beli', 'jumlah', 'harga_satuan', 'catatan'])));

            if ($kunci->isDirty('bahan_baku_id')) {
                $kunci->satuan = $this->bahanSppg((int) $kunci->bahan_baku_id, (int) $kunci->sppg_id)->satuan;
            }

            $total = self::hitungTotal((string) $kunci->getAttributes()['jumlah'], (string) $kunci->getAttributes()['harga_satuan']);
            self::pastikanTotalMuat($total);

            $kunci->forceFill([
                'total' => $total,
                'status' => StatusPembelianBahan::Diajukan,
                'versi' => (int) $kunci->versi + 1,
                'diverifikasi_oleh' => null,
                'diverifikasi_pada' => null,
                'alasan_penolakan' => null,
            ])->save();

            $this->ikatNota($kunci, $data['lampiran_ids'] ?? [], $pengguna);

            return $kunci;
        });
    }

    /**
     * Verifikasi akuntan. Setuju -> pengeluaran bahan_baku `disetujui`; tolak -> alasan tersimpan.
     */
    public function verifikasi(User $akuntan, PembelianBahan $pembelian, string $keputusan, ?string $alasan, int $versi): PembelianBahan
    {
        return DB::transaction(function () use ($akuntan, $pembelian, $keputusan, $alasan, $versi): PembelianBahan {
            $this->budget->kunciSppg((int) $pembelian->sppg_id);
            $kunci = PembelianBahan::withoutGlobalScope(SppgScope::class)->whereKey($pembelian->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $kunci->sppg_id !== (int) $akuntan->sppg_id) {
                abort(404);
            }

            if ($kunci->status !== StatusPembelianBahan::Diajukan) {
                throw GalatAuth::respons(409, self::ALASAN_STATUS, 'Pembelian ini tidak menunggu verifikasi.', tambahan: ['status' => $kunci->status->value]);
            }

            if ((int) $kunci->versi !== $versi) {
                throw GalatAuth::respons(409, self::ALASAN_VERSI, 'Pembelian sudah berubah. Muat ulang lalu verifikasi kembali.', tambahan: ['versi_server' => (int) $kunci->versi]);
            }

            if ($keputusan === 'ditolak') {
                $kunci->forceFill([
                    'status' => StatusPembelianBahan::Ditolak,
                    'alasan_penolakan' => $alasan,
                    'diverifikasi_oleh' => $akuntan->getKey(),
                    'diverifikasi_pada' => now(),
                ])->save();

                return $kunci;
            }

            $kunci->loadMissing('bahanBaku');
            $nominal = (int) self::pembulatan((string) $kunci->getAttributes()['total']);

            $pengeluaran = new Pengeluaran;
            $pengeluaran->forceFill([
                'sppg_id' => $kunci->sppg_id,
                'status_persetujuan' => StatusPersetujuanPengeluaran::Diajukan,
                'versi' => 1,
                'pembelian_bahan_id' => $kunci->getKey(),
            ]);
            $pengeluaran->fill([
                'pemasok_id' => $kunci->pemasok_id,
                'dicatat_oleh' => $akuntan->getKey(),
                'tanggal' => $kunci->tanggal_beli->toDateString(),
                'kategori' => KategoriPengeluaran::BahanBaku,
                'uraian' => Str::limit(sprintf('Pembelian %s %s %s', $kunci->bahanBaku?->nama ?? 'bahan', self::tampilkanJumlah((string) $kunci->getAttributes()['jumlah']), $kunci->satuan), 255, ''),
                'nominal' => $nominal,
                'melebihi_anggaran' => false,
            ]);
            $pengeluaran->save();

            // Pembelian terverifikasi tidak melewati investor (hanya pengeluaran non-menu).
            $this->budget->setujuiPengeluaran($pengeluaran);

            $kunci->forceFill([
                'status' => StatusPembelianBahan::Terverifikasi,
                'alasan_penolakan' => null,
                'diverifikasi_oleh' => $akuntan->getKey(),
                'diverifikasi_pada' => now(),
                'pengeluaran_id' => $pengeluaran->getKey(),
            ])->save();

            return $kunci;
        });
    }

    /** Harga pemasok terkini (informatif untuk akuntan) atau null bila tidak ada entri. */
    public function hargaReferensi(PembelianBahan $pembelian): ?HargaPemasok
    {
        return HargaPemasok::withoutGlobalScope(SppgScope::class)
            ->where('sppg_id', $pembelian->sppg_id)
            ->where('pemasok_id', $pembelian->pemasok_id)
            ->where('bahan_baku_id', $pembelian->bahan_baku_id)
            ->whereDate('berlaku_mulai', '<=', now()->toDateString())
            ->orderByDesc('berlaku_mulai')->orderByDesc('id')
            ->first();
    }

    /** Total terbesar yang muat di kolom decimal(15,2) dan batas nominal pengeluaran. */
    public const TOTAL_MAKS = '9999999999999';

    /** @throws ValidationException bila total melebihi batas penyimpanan (cegah galat basis data/500). */
    private static function pastikanTotalMuat(string $total): void
    {
        if (bccomp(substr($total, 0, -3), self::TOTAL_MAKS, 0) > 0) {
            throw ValidationException::withMessages(['harga_satuan' => ['Total pembelian (jumlah x harga) terlalu besar.']]);
        }
    }

    /** round(jumlah x harga) rupiah bulat, setengah ke atas, string desimal "N.00" (kolom decimal(15,2)). */
    public static function hitungTotal(string $jumlah, string $hargaSatuan): string
    {
        return self::pembulatan(bcmul(self::normal($jumlah, 3), self::normal($hargaSatuan, 2), 6)).'.00';
    }

    /** Pembulatan setengah-ke-atas ke bilangan bulat tanpa float. */
    private static function pembulatan(string $nilai): string
    {
        return bcadd(bcadd($nilai, '0.5', 6), '0', 0);
    }

    /** Angka desimal tetap tanpa notasi ilmiah (bcmath menolak "1.0E-5"). */
    private static function normal(string $nilai, int $desimal): string
    {
        return sprintf('%.'.$desimal.'F', (float) $nilai);
    }

    /** "12.500" -> "12,5"; "3.000" -> "3". */
    private static function tampilkanJumlah(string $jumlah): string
    {
        return str_replace('.', ',', rtrim(rtrim($jumlah, '0'), '.'));
    }

    private function bahanSppg(int $id, int $sppgId): BahanBaku
    {
        $bahan = BahanBaku::withoutGlobalScope(SppgScope::class)->where('sppg_id', $sppgId)->whereKey($id)->first();
        abort_if($bahan === null, 422, 'Bahan baku tidak ditemukan.');

        return $bahan;
    }

    /** @param array<int, mixed> $ids */
    private function ikatNota(PembelianBahan $pembelian, array $ids, User $pengguna): void
    {
        $this->lampiran->ikat($ids, $pembelian, 'pembelian-bahan', $pengguna);

        if ($pembelian->nota_path === null) {
            $pertama = $pembelian->lampirans()->first();

            if ($pertama !== null) {
                $pembelian->forceFill(['nota_path' => $pertama->path])->save();
            }
        }
    }
}
