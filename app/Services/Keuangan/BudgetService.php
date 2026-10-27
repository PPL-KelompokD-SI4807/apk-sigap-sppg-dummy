<?php

namespace App\Services\Keuangan;

use App\Enums\KategoriPengeluaran;
use App\Enums\StatusPersetujuanPengeluaran;
use App\Events\AnggaranEvent;
use App\Events\AnggaranMelampaui;
use App\Events\AnggaranMencapai100;
use App\Events\AnggaranMencapai80;
use App\Models\PencairanDana;
use App\Models\Pengeluaran;
use App\Models\PeringatanAnggaran;
use App\Models\Scopes\SppgScope;
use App\Models\User;
use App\Services\Auth\GalatAuth;
use App\Services\Persetujuan\PersetujuanPengeluaranService;
use App\Services\Storage\GambarSiap;
use App\Services\Storage\SecureUploadService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Anggaran per periode (PBI 14, FR-09, FR-10). Semua nominal = rupiah bulat (int), tanpa float.
 *
 * Definisi (konsisten dengan DailySummaryService):
 *   periode   = bulan 'YYYY-MM'
 *   anggaran  = Sum(pencairan_danas.nominal) dengan periode tsb
 *   terpakai  = Sum(pengeluarans.nominal) dengan tanggal di bulan tsb DAN status_persetujuan = disetujui
 *               (R-06b-2: pengeluaran yang masih menunggu/ditolak investor tidak dihitung)
 *   sisa      = anggaran - terpakai; persen = terpakai / anggaran * 100 (null bila anggaran 0)
 *
 * Keputusan FR-09: pengeluaran yang melebihi sisa anggaran TETAP DICATAT (data berasal dari
 * dokumen sumber; proposal meminta peringatan 80%/100%, bukan blokir) dan ditandai
 * melebihi_anggaran=true. config('operasional.keuangan.tolak_melebihi_anggaran')=true -> 422.
 *
 * Atomik: setiap tulis keuangan mengunci baris sppgs (lockForUpdate) sebagai mutex per SPPG,
 * lalu menghitung ulang dan mengevaluasi ambang di transaksi yang sama.
 *
 * Idempoten: event AnggaranMencapai80 / AnggaranMencapai100 / AnggaranMelampaui dipancarkan
 * SEKALI per (sppg, periode, ambang) lewat insertOrIgnore pada indeks unik peringatan_anggarans.
 * Edit/hapus pengeluaran tidak pernah memancarkan ulang. Penanda hanya "dipersenjatai ulang"
 * bila ANGGARAN berubah (pencairan dibuat/diubah/dihapus) sehingga persen turun di bawah ambang.
 */
class BudgetService
{
    public const MODUL_PENCAIRAN = 'keuangan-pencairan';

    public const MODUL_PENGELUARAN = 'keuangan-pengeluaran';

    public const ALASAN_VERSI = 'versi_tidak_cocok';

    public const ALASAN_DARI_PEMBELIAN = 'pengeluaran_dari_pembelian';

    public const POLA_PERIODE = '/^\d{4}-(0[1-9]|1[0-2])$/';

    public function __construct(private readonly SecureUploadService $unggahan) {}

    public function periodeDari(CarbonInterface|string $tanggal): string
    {
        return ($tanggal instanceof CarbonInterface ? Carbon::instance($tanggal) : Carbon::parse($tanggal, $this->zona()))->format('Y-m');
    }

    public function periodeBerjalan(): string
    {
        return Carbon::now($this->zona())->format('Y-m');
    }

    /**
     * @return array{periode: string, anggaran: int, terpakai: int, sisa: int, persen: float|null, status: string,
     *               jumlah_pencairan: int, jumlah_pengeluaran: int, jumlah_melebihi_anggaran: int,
     *               menunggu_persetujuan: array{jumlah: int, nominal: int},
     *               per_kategori: list<array{kategori: string, label: string, nominal: int, jumlah: int, persen: float|null}>}
     */
    public function ringkasan(int $sppgId, string $periode): array
    {
        [$awal, $akhir] = $this->rentang($periode);

        $pencairan = PencairanDana::query()->withoutGlobalScope(SppgScope::class)
            ->where('sppg_id', $sppgId)->where('periode', $periode);
        $semua = fn () => Pengeluaran::query()->withoutGlobalScope(SppgScope::class)
            ->where('sppg_id', $sppgId)->whereDate('tanggal', '>=', $awal)->whereDate('tanggal', '<=', $akhir);
        // R-06b-2: hanya pengeluaran yang sudah disetujui yang dihitung ke serapan anggaran.
        $pengeluaran = fn () => $semua()->where('status_persetujuan', StatusPersetujuanPengeluaran::Disetujui->value);
        $menunggu = fn () => $semua()->where('status_persetujuan', StatusPersetujuanPengeluaran::Diajukan->value);

        $anggaran = $this->rupiah((clone $pencairan)->sum('nominal'));
        $terpakai = $this->rupiah($pengeluaran()->sum('nominal'));

        $perKategori = [];
        $baris = $pengeluaran()->toBase()->selectRaw('kategori, sum(nominal) as total, count(*) as jumlah')->groupBy('kategori')->get()->keyBy('kategori');

        foreach (KategoriPengeluaran::cases() as $kategori) {
            $nominal = $this->rupiah($baris[$kategori->value]->total ?? 0);
            $perKategori[] = [
                'kategori' => $kategori->value,
                'label' => $kategori->label(),
                'nominal' => $nominal,
                'jumlah' => (int) ($baris[$kategori->value]->jumlah ?? 0),
                'persen' => $terpakai > 0 ? round($nominal * 100 / $terpakai, 2) : null,
            ];
        }

        return [
            'periode' => $periode,
            'anggaran' => $anggaran,
            'terpakai' => $terpakai,
            'sisa' => $anggaran - $terpakai,
            'persen' => $this->persen($terpakai, $anggaran),
            'status' => $this->status($terpakai, $anggaran),
            'jumlah_pencairan' => (clone $pencairan)->count(),
            'jumlah_pengeluaran' => $pengeluaran()->count(),
            'jumlah_melebihi_anggaran' => $pengeluaran()->where('melebihi_anggaran', true)->count(),
            'menunggu_persetujuan' => [
                'jumlah' => $menunggu()->count(),
                'nominal' => $this->rupiah($menunggu()->sum('nominal')),
            ],
            'per_kategori' => $perKategori,
        ];
    }

    /** @param array<string, mixed> $data hasil validasi StorePencairanDanaRequest */
    public function catatPencairan(User $pencatat, int $sppgId, array $data, ?GambarSiap $bukti = null): PencairanDana
    {
        return DB::transaction(function () use ($pencatat, $sppgId, $data, $bukti) {
            $this->kunciSppg($sppgId);

            $pencairan = new PencairanDana;
            $pencairan->forceFill(['sppg_id' => $sppgId]);
            $pencairan->fill($this->ambil($data, ['periode', 'sumber_dana', 'tanggal_cair', 'no_referensi', 'catatan']) + [
                'dicatat_oleh' => $pencatat->getKey(),
                'nominal' => (int) $data['nominal'],
            ]);
            $pencairan->save();

            $this->lampirkan($pencairan, $bukti, self::MODUL_PENCAIRAN, 'bukti', 'bukti_path', $pencatat);
            $this->evaluasiAmbang($sppgId, $pencairan->periode, true);

            return $pencairan;
        });
    }

    /** @param array<string, mixed> $data hasil validasi UpdatePencairanDanaRequest (key yang dikirim) */
    public function ubahPencairan(User $pengguna, PencairanDana $pencairan, array $data, ?GambarSiap $bukti = null): PencairanDana
    {
        return DB::transaction(function () use ($pengguna, $pencairan, $data, $bukti) {
            $sppgId = (int) $pencairan->sppg_id;
            $this->kunciSppg($sppgId);
            $pencairan = PencairanDana::query()->whereKey($pencairan->getKey())->lockForUpdate()->firstOrFail();
            $periodeLama = $pencairan->periode;

            $pencairan->fill($this->ambil($data, ['periode', 'sumber_dana', 'tanggal_cair', 'no_referensi', 'catatan']));

            if (array_key_exists('nominal', $data)) {
                $pencairan->nominal = (int) $data['nominal'];
            }

            $pencairan->save();
            $this->lampirkan($pencairan, $bukti, self::MODUL_PENCAIRAN, 'bukti', 'bukti_path', $pengguna);

            foreach (array_unique([$periodeLama, $pencairan->periode]) as $periode) {
                $this->evaluasiAmbang($sppgId, $periode, true);
            }

            return $pencairan;
        });
    }

    public function hapusPencairan(PencairanDana $pencairan): void
    {
        DB::transaction(function () use ($pencairan) {
            $sppgId = (int) $pencairan->sppg_id;
            $this->kunciSppg($sppgId);
            $pencairan = PencairanDana::query()->whereKey($pencairan->getKey())->lockForUpdate()->firstOrFail();
            $pencairan->delete();
            $this->evaluasiAmbang($sppgId, $pencairan->periode, true);
        });
    }

    /**
     * Catat pengeluaran langsung akuntan. `nominal >= ambang` -> `diajukan` + pengajuan ke investor;
     * di bawah ambang (hanya bila ambang > 0) -> langsung `disetujui` (cek sisa + ambang 80/100% saat itu).
     *
     * @param  array<string, mixed>  $data  hasil validasi StorePengeluaranRequest
     */
    public function catatPengeluaran(User $pencatat, int $sppgId, array $data, ?GambarSiap $nota = null): Pengeluaran
    {
        return DB::transaction(function () use ($pencatat, $sppgId, $data, $nota) {
            $this->kunciSppg($sppgId);
            $nominal = (int) $data['nominal'];

            $pengeluaran = new Pengeluaran;
            $pengeluaran->forceFill([
                'sppg_id' => $sppgId,
                'status_persetujuan' => StatusPersetujuanPengeluaran::Diajukan,
                'versi' => 1,
            ]);
            $pengeluaran->fill($this->ambil($data, ['pencairan_dana_id', 'pemasok_id', 'tanggal', 'kategori', 'uraian']) + [
                'dicatat_oleh' => $pencatat->getKey(),
                'nominal' => $nominal,
                'melebihi_anggaran' => false,
            ]);
            $pengeluaran->save();

            $this->lampirkan($pengeluaran, $nota, self::MODUL_PENGELUARAN, 'nota', 'nota_path', $pencatat);
            $this->ajukanAtauSetujui($pencatat, $pengeluaran, $nominal);

            return $pengeluaran;
        });
    }

    /**
     * Ubah pengeluaran (kunci optimistik `versi`). Perubahan apa pun menaikkan versi, mengembalikan status ke
     * `diajukan` (pengajuan lama yang masih menunggu dibatalkan, pengajuan baru dibuat). Pengeluaran hasil
     * verifikasi pembelian tidak dapat diubah lewat sini.
     *
     * @param  array<string, mixed>  $data  hasil validasi UpdatePengeluaranRequest (key yang dikirim)
     */
    public function ubahPengeluaran(User $pengguna, Pengeluaran $pengeluaran, array $data, int $versi, ?GambarSiap $nota = null): Pengeluaran
    {
        return DB::transaction(function () use ($pengguna, $pengeluaran, $data, $versi, $nota) {
            $sppgId = (int) $pengeluaran->sppg_id;
            $this->kunciSppg($sppgId);
            $pengeluaran = Pengeluaran::query()->whereKey($pengeluaran->getKey())->lockForUpdate()->firstOrFail();

            $this->pastikanBisaDiubah($pengeluaran, $versi);

            $pengeluaran->fill($this->ambil($data, ['pencairan_dana_id', 'pemasok_id', 'tanggal', 'kategori', 'uraian']));

            if (array_key_exists('nominal', $data)) {
                $pengeluaran->nominal = (int) $data['nominal'];
            }

            $pengeluaran->forceFill([
                'versi' => (int) $pengeluaran->versi + 1,
                'status_persetujuan' => StatusPersetujuanPengeluaran::Diajukan,
                'melebihi_anggaran' => false,
            ]);
            $pengeluaran->save();

            $this->persetujuan()->batalkanMenunggu($pengeluaran, PersetujuanPengeluaranService::CATATAN_DIUBAH);
            $this->lampirkan($pengeluaran, $nota, self::MODUL_PENGELUARAN, 'nota', 'nota_path', $pengguna);
            $this->ajukanAtauSetujui($pengguna, $pengeluaran, $this->rupiah($pengeluaran->getAttributes()['nominal']));

            return $pengeluaran;
        });
    }

    public function hapusPengeluaran(Pengeluaran $pengeluaran): void
    {
        DB::transaction(function () use ($pengeluaran) {
            $this->kunciSppg((int) $pengeluaran->sppg_id);
            $kunci = Pengeluaran::query()->whereKey($pengeluaran->getKey())->lockForUpdate()->firstOrFail();

            if ($kunci->pembelian_bahan_id !== null) {
                throw GalatAuth::respons(409, self::ALASAN_DARI_PEMBELIAN, 'Pengeluaran ini berasal dari pembelian bahan terverifikasi dan tidak dapat dihapus.');
            }

            $this->persetujuan()->batalkanMenunggu($kunci, PersetujuanPengeluaranService::CATATAN_DIHAPUS);
            $kunci->delete();
        });
    }

    /**
     * Setujui pengeluaran (keputusan investor, verifikasi pembelian, atau di bawah ambang): hitung
     * `melebihi_anggaran` terhadap sisa anggaran (hanya pengeluaran disetujui lain) lalu evaluasi ambang
     * 80/100%. Pemanggil WAJIB sudah mengunci sppgs + baris pengeluaran di transaksi yang sama, dan status
     * tersimpan pengeluaran belum `disetujui`.
     */
    public function setujuiPengeluaran(Pengeluaran $pengeluaran): void
    {
        $sppgId = (int) $pengeluaran->sppg_id;
        $periode = $this->periodeDari($pengeluaran->tanggal);
        $nominal = $this->rupiah($pengeluaran->getAttributes()['nominal']);
        $r = $this->ringkasan($sppgId, $periode);
        $melebihi = $this->cekSisa($nominal, $r['anggaran'] - $r['terpakai']);

        $pengeluaran->forceFill([
            'status_persetujuan' => StatusPersetujuanPengeluaran::Disetujui,
            'melebihi_anggaran' => $melebihi,
        ])->save();

        $this->evaluasiAmbang($sppgId, $periode, false);
    }

    /** True bila pengeluaran bernominal ini wajib disetujui investor (nominal >= ambang; ambang 0 = semua). */
    public function perluPersetujuan(int $nominal): bool
    {
        return $nominal >= max(0, (int) config('operasional.keuangan.ambang_persetujuan_pengeluaran', 0));
    }

    private function ajukanAtauSetujui(User $pengaju, Pengeluaran $pengeluaran, int $nominal): void
    {
        if ($this->perluPersetujuan($nominal)) {
            $this->persetujuan()->ajukan($pengaju, $pengeluaran, $nominal);

            return;
        }

        $this->setujuiPengeluaran($pengeluaran);
    }

    private function pastikanBisaDiubah(Pengeluaran $pengeluaran, int $versi): void
    {
        if ($pengeluaran->pembelian_bahan_id !== null) {
            throw GalatAuth::respons(409, self::ALASAN_DARI_PEMBELIAN, 'Pengeluaran ini berasal dari pembelian bahan terverifikasi dan tidak dapat diubah.');
        }

        if ((int) $pengeluaran->versi !== $versi) {
            throw GalatAuth::respons(409, self::ALASAN_VERSI, 'Pengeluaran sudah berubah. Muat ulang lalu ubah kembali.', tambahan: ['versi_server' => (int) $pengeluaran->versi]);
        }
    }

    private function persetujuan(): PersetujuanPengeluaranService
    {
        return app(PersetujuanPengeluaranService::class);
    }

    /**
     * Pancarkan event ambang yang baru terlewati (sekali per periode). $persenjataiUlang: hapus
     * penanda yang kondisinya tidak lagi terpenuhi (hanya saat anggaran berubah).
     */
    public function evaluasiAmbang(int $sppgId, string $periode, bool $persenjataiUlang): void
    {
        $r = $this->ringkasan($sppgId, $periode);
        $anggaran = $r['anggaran'];
        $terpakai = $r['terpakai'];
        $ambang80 = max(1, (int) config('operasional.keuangan.ambang_peringatan_persen', 80));

        $kondisi = [
            '80' => [$anggaran > 0 && $terpakai * 100 >= $anggaran * $ambang80, AnggaranMencapai80::class],
            '100' => [$anggaran > 0 && $terpakai >= $anggaran, AnggaranMencapai100::class],
            'melampaui' => [$anggaran > 0 && $terpakai > $anggaran, AnggaranMelampaui::class],
        ];

        $penanda = fn () => PeringatanAnggaran::query()->withoutGlobalScope(SppgScope::class)
            ->where('sppg_id', $sppgId)->where('periode', $periode);

        if ($persenjataiUlang) {
            $tidakBerlaku = array_keys(array_filter($kondisi, fn ($k) => ! $k[0]));

            if ($tidakBerlaku !== []) {
                $penanda()->whereIn('ambang', $tidakBerlaku)->delete();
            }
        }

        $persen = (float) ($r['persen'] ?? 0.0);
        $sekarang = now();

        foreach ($kondisi as $ambang => [$terpenuhi, $kelas]) {
            if (! $terpenuhi) {
                continue;
            }

            $baru = DB::table('peringatan_anggarans')->insertOrIgnore([
                'sppg_id' => $sppgId,
                'periode' => $periode,
                'ambang' => (string) $ambang,
                'anggaran' => $anggaran,
                'terpakai' => $terpakai,
                'persen' => $persen,
                'created_at' => $sekarang,
                'updated_at' => $sekarang,
            ]);

            if ($baru > 0) {
                /** @var class-string<AnggaranEvent> $kelas */
                event(new $kelas($sppgId, $periode, $anggaran, $terpakai, $persen));
            }
        }
    }

    /** Rupiah bulat dari nilai DB (string "150000.00", int, atau float SQLite) tanpa aritmetika float. */
    public function rupiah(mixed $nilai): int
    {
        if ($nilai === null || $nilai === '') {
            return 0;
        }

        if (is_int($nilai)) {
            return $nilai;
        }

        if (is_float($nilai)) {
            return (int) round($nilai);
        }

        $teks = trim((string) $nilai);
        $negatif = str_starts_with($teks, '-');
        [$bulat, $pecahan] = array_pad(explode('.', ltrim($teks, '-+'), 2), 2, '');
        $hasil = (int) ($bulat === '' ? '0' : $bulat);

        if ($pecahan !== '' && (int) $pecahan[0] >= 5) {
            $hasil++;
        }

        return $negatif ? -$hasil : $hasil;
    }

    private function persen(int $terpakai, int $anggaran): ?float
    {
        return $anggaran > 0 ? round($terpakai * 100 / $anggaran, 2) : null;
    }

    private function status(int $terpakai, int $anggaran): string
    {
        return match (true) {
            $anggaran <= 0 => $terpakai > 0 ? 'melampaui' : 'belum_ada_anggaran',
            $terpakai > $anggaran => 'melampaui',
            $terpakai === $anggaran => 'habis',
            $terpakai * 100 >= $anggaran * (int) config('operasional.keuangan.ambang_peringatan_persen', 80) => 'peringatan',
            default => 'aman',
        };
    }

    /** True bila melebihi sisa; lempar 422 bila konfigurasi menolak. */
    private function cekSisa(int $nominal, int $sisa): bool
    {
        $melebihi = $nominal > $sisa;

        if ($melebihi && (bool) config('operasional.keuangan.tolak_melebihi_anggaran', false)) {
            throw ValidationException::withMessages([
                'nominal' => [sprintf('Nominal melebihi sisa anggaran periode (sisa Rp%s).', number_format(max(0, $sisa), 0, ',', '.'))],
            ]);
        }

        return $melebihi;
    }

    /** @return array{0: string, 1: string} */
    private function rentang(string $periode): array
    {
        if (preg_match(self::POLA_PERIODE, $periode) !== 1) {
            throw ValidationException::withMessages(['periode' => ['Periode harus berformat YYYY-MM.']]);
        }

        $awal = Carbon::createFromFormat('Y-m-d', $periode.'-01', $this->zona())->startOfDay();

        return [$awal->toDateString(), $awal->copy()->endOfMonth()->toDateString()];
    }

    /** Mutex per SPPG (urutan kunci pertama untuk semua tulis keuangan). */
    public function kunciSppg(int $sppgId): void
    {
        DB::table('sppgs')->where('id', $sppgId)->lockForUpdate()->first(['id']);
    }

    private function lampirkan(PencairanDana|Pengeluaran $model, ?GambarSiap $gambar, string $modul, string $kategori, string $kolom, User $pengunggah): void
    {
        if ($gambar === null) {
            return;
        }

        $lampiran = $this->unggahan->simpan($gambar, $model, $modul, $pengunggah, $kategori);
        $model->{$kolom} = $lampiran->path;
        $model->save();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $kunci
     * @return array<string, mixed>
     */
    private function ambil(array $data, array $kunci): array
    {
        return array_intersect_key($data, array_flip($kunci));
    }

    private function zona(): string
    {
        return (string) config('operasional.timezone', 'Asia/Jakarta');
    }
}
