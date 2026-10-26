<?php

namespace App\Services\Storage;

use App\Enums\StatusPengajuanPengeluaran;
use App\Enums\StatusPersetujuanPengeluaran;
use App\Models\PembelianBahan;
use App\Models\Pengeluaran;
use App\Models\PersetujuanPengeluaran;
use App\Models\Scopes\SppgScope;
use App\Services\Auth\GalatAuth;
use Illuminate\Database\Eloquent\Model;

/**
 * Penjaga penambahan lampiran ke record induk yang SUDAH ada (POST /lampiran mode terikat), R-06b-2 (M1/M2).
 *
 * Dipanggil di dalam transaksi LampiranController. Mengunci baris induk, memeriksa ulang status di bawah kunci,
 * lalu untuk induk berversi (pembelian bahan, pengeluaran) MENAIKKAN `versi` agar keputusan pihak lain yang
 * dibuat dari layar lama (verifikasi akuntan, keputusan investor) gagal 409 `versi_tidak_cocok` bila himpunan
 * bukti berubah sejak versi yang dilihatnya (TOCTOU). Modul lain: hanya kunci baris (perilaku lama).
 *
 * - PembelianBahan terverifikasi: 409 `pembelian_sudah_diverifikasi` (nota final).
 * - Pengeluaran hasil pembelian: 409 `pengeluaran_dari_pembelian`.
 * - Pengeluaran sudah disetujui: 409 `pengeluaran_sudah_disetujui` (ubah lewat PATCH agar diajukan ulang).
 * - Pengeluaran diajukan/ditolak: boleh; versi naik dan pengajuan yang masih menunggu diselaraskan.
 *
 * Urutan kunci: hanya baris induk (tidak pernah mengunci sppgs sesudahnya), sehingga tidak berbalik arah dengan
 * urutan sppgs -> induk milik BudgetService/PembelianBahanService/PersetujuanPengeluaranService.
 */
class PenjagaLampiranInduk
{
    public const ALASAN_PEMBELIAN_FINAL = 'pembelian_sudah_diverifikasi';

    public const ALASAN_DARI_PEMBELIAN = 'pengeluaran_dari_pembelian';

    public const ALASAN_SUDAH_DISETUJUI = 'pengeluaran_sudah_disetujui';

    /** @return Model baris induk terkunci (segar) */
    public function kunciDanPeriksa(Model $induk): Model
    {
        $kunci = $induk->newQueryWithoutScopes()->whereKey($induk->getKey())->lockForUpdate()->first();
        abort_if($kunci === null, 404);

        if ($kunci instanceof PembelianBahan) {
            $this->jagaPembelian($kunci);
        } elseif ($kunci instanceof Pengeluaran) {
            $this->jagaPengeluaran($kunci);
        }

        return $kunci;
    }

    private function jagaPembelian(PembelianBahan $pembelian): void
    {
        if (! $pembelian->status->bisaDiubah()) {
            throw GalatAuth::respons(409, self::ALASAN_PEMBELIAN_FINAL, 'Pembelian sudah diverifikasi; nota tidak dapat ditambah.');
        }

        $pembelian->forceFill(['versi' => (int) $pembelian->versi + 1])->save();
    }

    private function jagaPengeluaran(Pengeluaran $pengeluaran): void
    {
        if ($pengeluaran->pembelian_bahan_id !== null) {
            throw GalatAuth::respons(409, self::ALASAN_DARI_PEMBELIAN, 'Pengeluaran ini berasal dari pembelian bahan terverifikasi; lampiran tidak dapat ditambah.');
        }

        if ($pengeluaran->status_persetujuan === StatusPersetujuanPengeluaran::Disetujui) {
            throw GalatAuth::respons(409, self::ALASAN_SUDAH_DISETUJUI, 'Pengeluaran sudah disetujui. Ubah pengeluaran agar diajukan ulang ke investor sebelum menambah lampiran.');
        }

        $baru = (int) $pengeluaran->versi + 1;
        $pengeluaran->forceFill(['versi' => $baru])->save();

        // Pengajuan yang masih menunggu mengikuti versi baru; investor dengan layar lama tetap 409 lalu memuat ulang.
        PersetujuanPengeluaran::withoutGlobalScope(SppgScope::class)
            ->where('pengeluaran_id', $pengeluaran->getKey())
            ->where('status', StatusPengajuanPengeluaran::Diajukan->value)
            ->update(['pengeluaran_versi' => $baru]);
    }
}
