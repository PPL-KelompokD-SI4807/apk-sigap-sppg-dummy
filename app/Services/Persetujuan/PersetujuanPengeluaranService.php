<?php

namespace App\Services\Persetujuan;

use App\Enums\StatusPengajuanPengeluaran;
use App\Enums\StatusPersetujuanPengeluaran;
use App\Events\PengeluaranDiajukan;
use App\Models\Pengeluaran;
use App\Models\PersetujuanPengeluaran;
use App\Models\Scopes\SppgScope;
use App\Models\User;
use App\Services\Auth\GalatAuth;
use App\Services\Keuangan\BudgetService;
use App\Support\SppgAktif;
use Illuminate\Support\Facades\DB;

/**
 * Persetujuan pengeluaran oleh investor (R-06b-2), pola PersetujuanMenuService.
 *
 * Urutan kunci baris SELALU: sppgs (mutex BudgetService::kunciSppg) -> pengeluarans -> persetujuan_pengeluarans.
 * Semua nilai snapshot (kategori, nominal, tanggal, ambang) diambil server dari record, bukan dari klien.
 * Investor hanya mengirim keputusan + catatan + versi.
 */
class PersetujuanPengeluaranService
{
    public const ALASAN_STATUS = 'status_tidak_valid';

    public const ALASAN_VERSI = 'versi_tidak_cocok';

    public const CATATAN_DIUBAH = 'Pengeluaran diubah setelah diajukan; pengajuan dibatalkan otomatis.';

    public const CATATAN_DIHAPUS = 'Pengeluaran dihapus setelah diajukan; pengajuan dibatalkan otomatis.';

    public function __construct(private readonly BudgetService $budget) {}

    /**
     * Buat pengajuan baru untuk pengeluaran yang baru dicatat/diubah. Dipanggil di dalam transaksi
     * BudgetService (sppgs + pengeluaran sudah terkunci).
     */
    public function ajukan(User $pengaju, Pengeluaran $pengeluaran, int $nominal): PersetujuanPengeluaran
    {
        $nomor = (int) PersetujuanPengeluaran::withoutGlobalScope(SppgScope::class)
            ->where('pengeluaran_id', $pengeluaran->getKey())->max('nomor') + 1;

        $pengajuan = new PersetujuanPengeluaran;
        $pengajuan->forceFill([
            'sppg_id' => $pengeluaran->sppg_id,
            'pengeluaran_id' => $pengeluaran->getKey(),
            'nomor' => $nomor,
            'pengeluaran_versi' => $pengeluaran->versi,
            'status' => StatusPengajuanPengeluaran::Diajukan,
            'kategori' => $pengeluaran->kategori->value,
            'nominal' => $nominal,
            'tanggal' => $pengeluaran->tanggal->toDateString(),
            'pemasok_id' => $pengeluaran->pemasok_id,
            'ambang_persetujuan' => max(0, (int) config('operasional.keuangan.ambang_persetujuan_pengeluaran', 0)),
            'diajukan_oleh' => $pengaju->getKey(),
            'diajukan_pada' => now(),
        ])->save();

        $periode = $this->budget->periodeDari($pengeluaran->tanggal);
        $r = $this->budget->ringkasan((int) $pengeluaran->sppg_id, $periode);

        event(PengeluaranDiajukan::dari($pengeluaran, $nominal, ['periode' => $periode, 'anggaran' => $r['anggaran'], 'terpakai' => $r['terpakai']]));

        return $pengajuan;
    }

    /** Batalkan pengajuan yang masih menunggu (pengeluaran diubah/dihapus). Pemanggil sudah memegang kunci. */
    public function batalkanMenunggu(Pengeluaran $pengeluaran, string $catatan): void
    {
        $menunggu = PersetujuanPengeluaran::withoutGlobalScope(SppgScope::class)
            ->where('pengeluaran_id', $pengeluaran->getKey())
            ->where('status', StatusPengajuanPengeluaran::Diajukan->value)
            ->orderBy('id')->lockForUpdate()->get();

        foreach ($menunggu as $pengajuan) {
            $pengajuan->forceFill([
                'status' => StatusPengajuanPengeluaran::Dibatalkan,
                'diputuskan_oleh' => null,
                'diputuskan_pada' => now(),
                'catatan_keputusan' => $catatan,
            ])->save();
        }
    }

    /**
     * Keputusan investor. Gagal -> 409 terstruktur tanpa mengubah data apa pun. Setuju -> hitung
     * melebihi_anggaran + evaluasi ambang 80/100% (422 bila konfigurasi menolak melebihi anggaran).
     */
    public function putuskan(User $investor, Pengeluaran $pengeluaran, string $keputusan, ?string $catatan, int $versi): Pengeluaran
    {
        return DB::transaction(function () use ($investor, $pengeluaran, $keputusan, $catatan, $versi): Pengeluaran {
            // PERTAMA dalam transaksi: kunci sppgs sebelum pembacaan apa pun. Di MySQL REPEATABLE READ snapshot dibuat
            // pada pembacaan konsisten pertama; membaca dulu (mis. SppgAktif::milik) lalu menunggu kunci membuat
            // ringkasan anggaran memakai snapshot basi (R-06b-2 L2).
            $this->budget->kunciSppg((int) $pengeluaran->sppg_id);

            // Lapis kedua selain Policy: investor wajib pemilik SPPG pengeluaran (pivot investor_sppg).
            if (! in_array((int) $pengeluaran->sppg_id, app(SppgAktif::class)->milik($investor), true)) {
                abort(403);
            }

            $kunci = Pengeluaran::withoutGlobalScope(SppgScope::class)->whereKey($pengeluaran->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $kunci->sppg_id !== (int) $pengeluaran->sppg_id) {
                abort(404);
            }

            $pengajuan = PersetujuanPengeluaran::withoutGlobalScope(SppgScope::class)
                ->where('pengeluaran_id', $kunci->getKey())
                ->where('status', StatusPengajuanPengeluaran::Diajukan->value)
                ->orderByDesc('nomor')->lockForUpdate()->first();

            if ($kunci->status_persetujuan !== StatusPersetujuanPengeluaran::Diajukan || $pengajuan === null) {
                throw GalatAuth::respons(409, self::ALASAN_STATUS, 'Pengeluaran ini sudah diputuskan atau tidak menunggu persetujuan.', tambahan: ['status' => $kunci->status_persetujuan->value]);
            }

            if ((int) $kunci->versi !== $versi || (int) $pengajuan->pengeluaran_versi !== $versi) {
                throw GalatAuth::respons(409, self::ALASAN_VERSI, 'Pengeluaran sudah berubah. Muat ulang lalu putuskan kembali.', tambahan: ['versi_server' => (int) $kunci->versi]);
            }

            $pengajuan->forceFill([
                'status' => StatusPengajuanPengeluaran::from($keputusan),
                'diputuskan_oleh' => $investor->getKey(),
                'diputuskan_pada' => now(),
                'catatan_keputusan' => $catatan,
            ])->save();

            if ($keputusan === StatusPengajuanPengeluaran::Disetujui->value) {
                $this->budget->setujuiPengeluaran($kunci);
            } else {
                $kunci->forceFill(['status_persetujuan' => StatusPersetujuanPengeluaran::Ditolak])->save();
            }

            return $kunci;
        });
    }
}
