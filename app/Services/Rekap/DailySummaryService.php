<?php

namespace App\Services\Rekap;

use App\Enums\HasilMutu;
use App\Enums\StatusJadwalDistribusi;
use App\Enums\StatusJadwalProduksi;
use App\Enums\StatusKeluhan;
use App\Enums\StatusKirim;
use App\Models\BahanBaku;
use App\Models\BuktiDistribusi;
use App\Models\FeedbackKeluhan;
use App\Models\JadwalDistribusi;
use App\Models\JadwalProduksi;
use App\Models\LogProduksi;
use App\Models\PemeriksaanMutu;
use App\Models\PencairanDana;
use App\Models\Pengeluaran;
use App\Models\RekapHarian;
use App\Models\Scopes\SppgScope;
use App\Models\Sppg;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Agregasi Laporan Rekapitulasi Harian Operasional per SPPG (FR-13).
 *
 * Seluruh query memakai filter sppg_id eksplisit (bukan scope pengguna login) sehingga aman dari
 * scheduler/console maupun permintaan Kepala SPPG. Keuangan dibaca langsung dari PencairanDana dan
 * Pengeluaran (tidak bergantung pada BudgetService): periode = bulan tanggal rekap (Y-m),
 * pencairan = periode itu dengan tanggal_cair <= tanggal rekap, pengeluaran = sejak awal bulan sampai
 * tanggal rekap, sisa = pencairan - pengeluaran, serapan = pengeluaran / pencairan * 100.
 */
class DailySummaryService
{
    public function zona(): string
    {
        return (string) config('operasional.timezone', 'Asia/Jakarta');
    }

    public function hariIni(): Carbon
    {
        return Carbon::now($this->zona())->startOfDay();
    }

    public function parseTanggal(CarbonInterface|string|null $tanggal): Carbon
    {
        if ($tanggal === null) {
            return $this->hariIni();
        }

        return ($tanggal instanceof CarbonInterface
            ? Carbon::instance($tanggal)->setTimezone($this->zona())
            : Carbon::createFromFormat('Y-m-d', $tanggal, $this->zona()))->startOfDay();
    }

    /**
     * Hitung ringkasan satu hari (tidak menyimpan).
     *
     * @return array<string, mixed>
     */
    public function hitung(int $sppgId, CarbonInterface|string|null $tanggal = null): array
    {
        $t = $this->parseTanggal($tanggal);

        return [
            'tanggal' => $t->toDateString(),
            'sppg_id' => $sppgId,
            ...$this->bagianProduksi($sppgId, $t),
            'distribusi' => $this->bagianDistribusi($sppgId, $t),
            'stok' => $this->bagianStok($sppgId, $t),
            'keuangan' => $this->bagianKeuangan($sppgId, $t),
            'keluhan' => $this->bagianKeluhan($sppgId, $t),
        ];
    }

    /**
     * Susun (atau susun ulang) snapshot. Idempoten per (sppg_id, tanggal).
     *
     * @param  bool  $setCatatan  true = timpa catatan dengan $catatan (boleh null); false = pertahankan catatan lama
     */
    public function susun(int $sppgId, CarbonInterface|string|null $tanggal = null, ?User $oleh = null, ?string $catatan = null, bool $setCatatan = false): RekapHarian
    {
        $t = $this->parseTanggal($tanggal);
        $ringkasan = $this->hitung($sppgId, $t);

        $simpan = function () use ($sppgId, $t, $ringkasan, $oleh, $catatan, $setCatatan) {
            return DB::transaction(function () use ($sppgId, $t, $ringkasan, $oleh, $catatan, $setCatatan) {
                $rekap = RekapHarian::query()->withoutGlobalScope(SppgScope::class)
                    ->where('sppg_id', $sppgId)->whereDate('tanggal', $t->toDateString())
                    ->lockForUpdate()->first();

                if ($rekap === null) {
                    $rekap = new RekapHarian;
                    $rekap->forceFill(['sppg_id' => $sppgId, 'status_kirim' => StatusKirim::Menunggu]);
                    $rekap->tanggal = $t->toDateString();
                }

                $rekap->ringkasan = $ringkasan;
                $rekap->disusun_pada = Carbon::now($this->zona());
                $rekap->forceFill(['disusun_oleh' => $oleh?->getKey()]);

                if ($setCatatan) {
                    $rekap->catatan = $catatan;
                }

                $rekap->save();

                return $rekap;
            });
        };

        try {
            return $simpan();
        } catch (UniqueConstraintViolationException) {
            // Dua penyusun bersamaan: yang kalah mengulang sebagai pembaruan.
            return $simpan();
        }
    }

    /**
     * Susun rekap untuk semua SPPG aktif (scheduler 17:00 WIB). Kegagalan satu SPPG tidak
     * menghentikan yang lain.
     *
     * @return array{berhasil: list<RekapHarian>, gagal: array<int, string>}
     */
    public function susunSemuaSppgAktif(CarbonInterface|string|null $tanggal = null, ?int $hanyaSppgId = null): array
    {
        $hasil = ['berhasil' => [], 'gagal' => []];

        $sppgs = Sppg::query()->aktif()
            ->when($hanyaSppgId !== null, fn (Builder $q) => $q->whereKey($hanyaSppgId))
            ->orderBy('id')->get(['id']);

        foreach ($sppgs as $sppg) {
            try {
                $hasil['berhasil'][] = $this->susun((int) $sppg->id, $tanggal);
            } catch (Throwable $e) {
                report($e);
                Log::warning('Rekap harian gagal disusun', ['sppg_id' => $sppg->id, 'galat' => $e::class]);
                $hasil['gagal'][(int) $sppg->id] = $e::class;
            }
        }

        return $hasil;
    }

    /** @return array<string, mixed> */
    private function bagianProduksi(int $sppgId, Carbon $t): array
    {
        $jadwal = $this->q(JadwalProduksi::class, $sppgId)
            ->whereDate('tanggal_produksi', $t->toDateString())
            ->where('status', '!=', StatusJadwalProduksi::Dibatalkan->value)
            ->get(['id', 'target_porsi']);
        $ids = $jadwal->pluck('id')->all();

        $target = (int) $jadwal->sum('target_porsi');
        $log = $this->q(LogProduksi::class, $sppgId)->whereIn('jadwal_produksi_id', $ids);
        $realisasi = (int) (clone $log)->sum('realisasi_porsi');
        $sisa = (float) (clone $log)->sum('sisa_makanan_kg');
        $denganLog = (clone $log)->distinct()->count('jadwal_produksi_id');

        // Mutu: hasil pemeriksaan TERBARU per batch.
        $terbaru = [];

        foreach ($this->q(PemeriksaanMutu::class, $sppgId)->whereIn('jadwal_produksi_id', $ids)
            ->orderByDesc('waktu_pemeriksaan')->orderByDesc('id')->get(['jadwal_produksi_id', 'hasil']) as $p) {
            $terbaru[$p->jadwal_produksi_id] ??= $p->hasil;
        }

        $layak = count(array_filter($terbaru, fn ($h) => $h === HasilMutu::Layak));

        return [
            'porsi' => [
                'jumlah_jadwal' => count($ids),
                'target' => $target,
                'realisasi' => $realisasi,
                'capaian_persen' => $target > 0 ? round($realisasi / $target * 100, 2) : 0.0,
                'jadwal_tanpa_log' => count($ids) - $denganLog,
            ],
            'mutu' => [
                'batch' => count($ids),
                'layak' => $layak,
                'tidak_layak' => count($terbaru) - $layak,
                'belum_diperiksa' => count($ids) - count($terbaru),
            ],
            'sisa_makanan_kg' => round($sisa, 2),
        ];
    }

    /** @return array<string, int> */
    private function bagianDistribusi(int $sppgId, Carbon $t): array
    {
        $jadwal = $this->q(JadwalDistribusi::class, $sppgId)
            ->whereDate('tanggal_distribusi', $t->toDateString())
            ->get(['id', 'status']);

        $total = $jadwal->count();
        $tiba = $jadwal->where('status', StatusJadwalDistribusi::Diterima)->count();
        $batal = $jadwal->where('status', StatusJadwalDistribusi::Dibatalkan)->count();
        $terlambat = $this->q(BuktiDistribusi::class, $sppgId)
            ->whereIn('jadwal_distribusi_id', $jadwal->pluck('id')->all())
            ->where('terlambat', true)
            ->count();

        return [
            'total' => $total,
            'tiba' => $tiba,
            'terlambat' => $terlambat,
            'batal' => $batal,
            'belum_selesai' => $total - $tiba - $batal,
        ];
    }

    /** @return array<string, int> */
    private function bagianStok(int $sppgId, Carbon $t): array
    {
        $hari = (int) config('operasional.rekap.hari_peringatan_kedaluwarsa', 3);
        $bahan = fn () => $this->q(BahanBaku::class, $sppgId);

        return [
            'menipis' => $bahan()->stokMenipis()->count(),
            'sudah_kedaluwarsa' => $bahan()->whereNotNull('kedaluwarsa_terdekat')
                ->whereDate('kedaluwarsa_terdekat', '<', $t->toDateString())->count(),
            'akan_kedaluwarsa' => $bahan()->whereNotNull('kedaluwarsa_terdekat')
                ->whereDate('kedaluwarsa_terdekat', '>=', $t->toDateString())
                ->whereDate('kedaluwarsa_terdekat', '<=', $t->copy()->addDays($hari)->toDateString())->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function bagianKeuangan(int $sppgId, Carbon $t): array
    {
        $periode = $t->format('Y-m');
        $awal = $t->copy()->startOfMonth()->toDateString();

        $pencairan = $this->sen($this->q(PencairanDana::class, $sppgId)
            ->where('periode', $periode)->whereDate('tanggal_cair', '<=', $t->toDateString())->sum('nominal'));
        $periodeKeluar = $this->sen($this->q(Pengeluaran::class, $sppgId)
            ->whereDate('tanggal', '>=', $awal)->whereDate('tanggal', '<=', $t->toDateString())->sum('nominal'));
        $hariIni = $this->sen($this->q(Pengeluaran::class, $sppgId)
            ->whereDate('tanggal', $t->toDateString())->sum('nominal'));

        return [
            'periode' => $periode,
            'pencairan_periode' => $this->rupiah($pencairan),
            'pengeluaran_hari_ini' => $this->rupiah($hariIni),
            'pengeluaran_periode' => $this->rupiah($periodeKeluar),
            'sisa_anggaran' => $this->rupiah($pencairan - $periodeKeluar),
            'serapan_persen' => $pencairan > 0 ? round($periodeKeluar / $pencairan * 100, 2) : 0.0,
        ];
    }

    /** @return array<string, int> */
    private function bagianKeluhan(int $sppgId, Carbon $t): array
    {
        $keluhan = fn () => $this->q(FeedbackKeluhan::class, $sppgId);
        $hariIni = fn () => $keluhan()->whereDate('created_at', $t->toDateString());

        return [
            'baru' => $hariIni()->count(),
            'kritis' => $hariIni()->kritis()->count(),
            'terbuka' => $keluhan()->whereIn('status', StatusKeluhan::terbukaValues())
                ->whereDate('created_at', '<=', $t->toDateString())->count(),
        ];
    }

    /**
     * @param  class-string<Model>  $model
     * @return Builder<Model>
     */
    private function q(string $model, int $sppgId): Builder
    {
        return $model::query()->withoutGlobalScope(SppgScope::class)->where((new $model)->qualifyColumn('sppg_id'), $sppgId);
    }

    /** Nominal desimal -> sen (integer) agar penjumlahan bebas galat float. */
    private function sen(mixed $nominal): int
    {
        return (int) round(((float) $nominal) * 100);
    }

    private function rupiah(int $sen): string
    {
        return number_format($sen / 100, 2, '.', '');
    }
}
