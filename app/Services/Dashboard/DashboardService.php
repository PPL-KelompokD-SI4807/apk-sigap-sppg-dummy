<?php

namespace App\Services\Dashboard;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Http\Middleware\ResolveSppgAktif;
use App\Models\User;
use App\Services\Auth\GalatAuth;
use App\Services\Dashboard\Widget\AdminWidgets;
use App\Services\Dashboard\Widget\DistribusiWidgets;
use App\Services\Dashboard\Widget\GiziWidgets;
use App\Services\Dashboard\Widget\KeluhanWidgets;
use App\Services\Dashboard\Widget\KeuanganWidgets;
use App\Services\Dashboard\Widget\MutuWidgets;
use App\Services\Dashboard\Widget\ProduksiWidgets;
use App\Services\Dashboard\Widget\RekapWidgets;
use App\Services\Dashboard\Widget\StokWidgets;
use App\Services\Keuangan\BudgetService;
use App\Support\Rbac;
use App\Support\SppgAktif;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Dashboard laporan per peran (R-07, GET /api/v1/dashboard).
 *
 * Keamanan dan cakupan:
 *  - Himpunan SPPG ditentukan di sini dari sumber otoritatif (SppgAktif/users.sppg_id/pivot investor_sppg), bukan
 *    dari input mentah. Investor boleh memilih salah satu SPPG MILIKNYA (?sppg_id= atau header X-SPPG-Id), default
 *    gabungan seluruh SPPG miliknya; SPPG milik orang lain -> 403 `sppg_aktif_ditolak` (sama dengan SPPG yang tidak
 *    ada, tanpa membocorkan keberadaannya). Peran satu-SPPG selalu SPPG-nya. Admin lintas SPPG (opsional memilih satu).
 *  - Setiap widget hanya dihitung bila peran punya hak R (Rbac) pada modul sumbernya; selain itu bernilai null.
 *  - Hanya agregat (COUNT/SUM/GROUP BY, tanpa N+1) dan daftar operasional kecil; tidak ada nama/NIK/foto/teks bebas
 *    penerima manfaat atau keluhan. Agregat penerima manfaat memakai penyamaran sel kecil k (config/rbac.php).
 *  - Hasil di-cache 60 detik per (pengguna, peran/unit, himpunan SPPG, rentang, tanggal): tidak dibagi lintas pengguna.
 */
final class DashboardService
{
    public const RENTANG_DIIZINKAN = [7, 30];

    public const RENTANG_BAWAAN = 7;

    public const TTL_DETIK = 60;

    public function __construct(private readonly BudgetService $budget, private readonly SppgAktif $sppgAktif) {}

    /**
     * @return array<string, mixed> isi `data` respons
     */
    public function bangun(User $user, ?int $sppgId = null, int $rentang = self::RENTANG_BAWAAN): array
    {
        $rentang = in_array($rentang, self::RENTANG_DIIZINKAN, true) ? $rentang : self::RENTANG_BAWAAN;
        [$cakupan, $pilihan] = $this->cakupan($user, $sppgId, $rentang);

        $kunci = sprintf(
            'dashboard:v1:u%d:%s:%s:%s:s%s:r%d:%s',
            $user->getKey(),
            $user->role->value,
            $user->unit?->value ?? '-',
            $cakupan->mode,
            md5(implode(',', $cakupan->ids)),
            $rentang,
            $cakupan->hari(),
        );

        return Cache::remember($kunci, self::TTL_DETIK, fn () => $this->susun($cakupan, $pilihan));
    }

    /**
     * @return array{0: Cakupan, 1: list<array{id: int, kode: string, nama: string, status_operasional: string}>|null}
     */
    private function cakupan(User $user, ?int $diminta, int $rentang): array
    {
        $hariIni = CarbonImmutable::now((string) config('operasional.timezone', 'Asia/Jakarta'))->startOfDay();
        $investor = $user->isInvestor();

        if ($user->isAdmin()) {
            $kandidat = null;
        } elseif ($investor) {
            $kandidat = $this->sppgAktif->milik($user);
        } else {
            $sendiri = $this->sppgAktif->id($user);
            $kandidat = $sendiri === null ? [] : [$sendiri];
        }

        $baris = DB::table('sppgs')->whereNull('deleted_at')
            ->when($kandidat !== null, fn ($q) => $q->whereIn('id', $kandidat))
            ->orderBy('nama')->orderBy('id')
            ->get(['id', 'kode', 'nama', 'status_operasional'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'kode' => (string) $r->kode, 'nama' => (string) $r->nama, 'status_operasional' => (string) $r->status_operasional])
            ->all();

        if (! $user->isAdmin() && $baris === []) {
            throw GalatAuth::respons(403, SppgAktif::TANPA_SPPG, 'Akun Anda belum terhubung dengan SPPG mana pun. Hubungi Admin.');
        }

        // SPPG yang dipilih: ?sppg_id= lebih diutamakan daripada header X-SPPG-Id (investor).
        $pilih = $diminta ?? ($investor ? $this->sppgAktif->id($user) : null);

        if ($pilih === null) {
            $dipilih = $baris;
        } else {
            $dipilih = array_values(array_filter($baris, fn (array $s) => $s['id'] === $pilih));

            if ($dipilih === []) {
                abort_if($user->isAdmin(), 404, 'SPPG tidak ditemukan.');

                // R06a-S-08: upaya menebak ID SPPG terpantau (nilai dipotong 20 karakter, ASCII cetak), seperti ResolveSppgAktif.
                Log::notice('sppg_aktif.ditolak', [
                    'user_id' => $user->getKey(),
                    'sppg_id' => ResolveSppgAktif::potongHeader((string) $pilih),
                ]);

                throw GalatAuth::respons(403, SppgAktif::DITOLAK, 'SPPG yang dipilih tidak dapat Anda akses. Pilih ulang SPPG aktif.', tambahan: ['header' => SppgAktif::HEADER]);
            }
        }

        $ids = array_column($dipilih, 'id');
        $mode = $pilih !== null || (count($ids) <= 1 && ! $user->isAdmin()) ? Cakupan::MODE_SATU : Cakupan::MODE_GABUNGAN;

        return [
            new Cakupan($user, $ids, $dipilih, $mode, $hariIni, $rentang),
            $investor ? $baris : null,
        ];
    }

    /**
     * @param  list<array{id: int, kode: string, nama: string, status_operasional: string}>|null  $pilihan
     * @return array<string, mixed>
     */
    private function susun(Cakupan $c, ?array $pilihan): array
    {
        $user = $c->user;

        return [
            'peran' => $user->role->value,
            'unit' => $user->unit?->value,
            'profil' => $this->profil($user),
            'mode' => $c->mode,
            'rentang_hari' => $c->rentang,
            'periode' => [
                'zona_waktu' => (string) config('operasional.timezone', 'Asia/Jakarta'),
                'hari_ini' => $c->hari(),
                'bulan_berjalan' => ['bulan' => $c->bulan(), 'dari' => $c->awalBulan(), 'sampai' => $c->akhirBulan()],
                'tren' => ['rentang_hari' => $c->rentang, 'dari' => $c->mulaiTren(), 'sampai' => $c->hari()],
            ],
            'dibuat_pada' => now()->toIso8601String(),
            'cache_detik' => self::TTL_DETIK,
            'sppg' => $c->sppg,
            'sppg_pilihan' => $pilihan,
            'widgets' => $this->widgets($c),
        ];
    }

    private function profil(User $user): string
    {
        return $user->role === Role::Relawan ? 'relawan_'.($user->unit?->value ?? 'tanpa_unit') : $user->role->value;
    }

    /**
     * @return array<string, array<string, mixed>|null>
     */
    private function widgets(Cakupan $c): array
    {
        $user = $c->user;
        $distribusi = new DistribusiWidgets($c);
        $produksi = new ProduksiWidgets($c, $distribusi);
        $mutu = new MutuWidgets($c);
        $stok = new StokWidgets($c);
        $keluhan = new KeluhanWidgets($c);
        $keuangan = new KeuanganWidgets($c, $this->budget);
        $gizi = new GiziWidgets($c);
        $rekap = new RekapWidgets($c);
        $admin = new AdminWidgets($c, $stok);
        $pembelian = Rbac::can($user, 'pembelian-bahan', 'R');

        // kunci => [modul yang wajib dapat dibaca (R) | ['agregat:modul'], pembangun]
        $daftar = match (true) {
            $user->isAdmin() => [
                'operasional_lintas_sppg' => [['jadwal', 'log-produksi'], fn () => $admin->operasional()],
                'pengguna_aktif_per_peran' => [['pengguna'], fn () => $admin->penggunaAktif()],
                'login_gagal' => [['pengguna'], fn () => $admin->loginGagal()],
                'antrean_job_webhook' => [['pengguna'], fn () => $admin->antreanJobWebhook()],
                'draf_ai_tertunda' => [['pengguna'], fn () => $admin->drafAi()],
            ],
            $user->isInvestor() => [
                'porsi_hari_ini' => [['rekap-harian'], fn () => $produksi->porsiHariIni(true)],
                'anggaran' => [['keuangan'], fn () => $keuangan->anggaran()],
                'pengajuan_menunggu' => [['keuangan', 'persetujuan-menu'], fn () => $keuangan->pengajuanMenunggu()],
                'stok_kritis' => [['bahan-baku'], fn () => $stok->kritis()],
                'insiden_mutu' => [['mutu'], fn () => $mutu->insiden()],
                'keluhan_kritis' => [['keluhan'], fn () => $keluhan->kritis()],
                'bukti_nota' => [['keuangan'], fn () => $keuangan->buktiNota()],
                'tren' => [['rekap-harian', 'keuangan', 'keluhan', 'mutu'], fn () => $this->trenGabungan($c, $produksi, $keuangan, $keluhan, $mutu)],
            ],
            $user->role === Role::KepalaSppg => [
                'jadwal_produksi_hari_ini' => [['jadwal'], fn () => $produksi->jadwalHariIni()],
                'porsi_target_vs_realisasi' => [['log-produksi', 'jadwal'], fn () => $produksi->porsiHariIni(true)],
                'distribusi_per_titik' => [['jadwal', 'titik-layanan'], fn () => $distribusi->perTitik()],
                'mutu' => [['mutu'], fn () => $mutu->ringkas()],
                'keluhan_terbuka' => [['keluhan'], fn () => $keluhan->terbuka()],
                'stok_kritis' => [['bahan-baku'], fn () => $stok->kritis()],
                'anggaran' => [['keuangan'], fn () => $keuangan->anggaran()],
                'bukti_nota' => [['keuangan'], fn () => $keuangan->buktiNota()],
                'pengajuan_menunggu_investor' => [['keuangan', 'persetujuan-menu'], fn () => $keuangan->pengajuanMenunggu()],
                'rekap_harian' => [['rekap-harian'], fn () => $rekap->status()],
            ],
            $user->role === Role::AhliGizi => [
                'menu_hari_ini' => [['menu-gizi'], fn () => $gizi->menu()],
                'gizi_vs_standar' => [['menu-gizi'], fn () => $gizi->giziVsStandar()],
                'biaya_per_porsi' => [['menu-gizi', 'persetujuan-menu'], fn () => $gizi->biayaPerPorsi()],
                'kebutuhan_khusus' => [['agregat:penerima-manfaat'], fn () => $gizi->kebutuhanKhusus()],
                'hasil_mutu' => [['mutu'], fn () => $mutu->ringkas()],
            ],
            $user->role === Role::Akuntan => [
                'pencairan_vs_pengeluaran' => [['keuangan'], fn () => $keuangan->pencairanVsPengeluaran()],
                'serapan_per_kategori' => [['keuangan'], fn () => $keuangan->serapanPerKategori()],
                'pengeluaran_menunggu' => [['keuangan'], fn () => $keuangan->pengeluaranMenunggu()],
                'selisih_tren' => [['keuangan'], fn () => $keuangan->selisihTren()],
                'bukti_nota' => [['keuangan'], fn () => $keuangan->buktiNota()],
            ],
            $user->role === Role::StafGudang => [
                'stok_per_bahan' => [['bahan-baku'], fn () => $stok->perBahan()],
                'stok_di_bawah_minimum' => [['bahan-baku'], fn () => $stok->diBawahMinimum()],
                'stok_mendekati_kedaluwarsa' => [['bahan-baku'], fn () => $stok->mendekatiKedaluwarsa()],
                'mutasi_hari_ini' => [['bahan-baku'], fn () => $stok->mutasiHariIni()],
                'pengiriman_pemasok' => [['bahan-baku'], fn () => $stok->pengirimanPemasok($pembelian)],
            ],
            $user->isRelawanUnit(RelawanUnit::Dapur) => [
                'menu_target_hari_ini' => [['jadwal', 'menu-gizi'], fn () => $produksi->menuTargetHariIni()],
                'status_log_produksi' => [['log-produksi'], fn () => $produksi->statusLogProduksi()],
                'checklist_mutu_belum_diisi' => [['mutu'], fn () => $mutu->checklistBelumDiisi()],
            ],
            $user->isRelawanUnit(RelawanUnit::Distribusi) => [
                'rute_hari_ini' => [['jadwal', 'titik-layanan'], fn () => $distribusi->rute()],
                'status_kirim' => [['jadwal', 'notifikasi-perjalanan'], fn () => $distribusi->statusKirim()],
                'bukti_belum_diunggah' => [['bukti-distribusi'], fn () => $distribusi->buktiBelumDiunggah()],
                'armada_pengemudi' => [['armada'], fn () => $distribusi->armadaPengemudi()],
            ],
            default => [],
        };

        $hasil = [];

        foreach ($daftar as $kunci => [$modul, $bangun]) {
            $hasil[$kunci] = $this->bolehBaca($user, $modul) ? $bangun() : null;
        }

        return $hasil;
    }

    /**
     * Hak baca peran atas seluruh modul sumber widget. Awalan "agregat:" = cukup hak agregat (investor/peran R penuh).
     *
     * @param  list<string>  $modul
     */
    private function bolehBaca(User $user, array $modul): bool
    {
        foreach ($modul as $m) {
            $ok = str_starts_with($m, 'agregat:')
                ? Rbac::canAggregate($user, substr($m, 8))
                : Rbac::can($user, $m, 'R') && ! Rbac::isSelfOnly($user, $m, 'R');

            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    /**
     * Tren gabungan investor (7/30 hari): porsi, pengeluaran disetujui, keluhan masuk, pemeriksaan tidak layak.
     *
     * @return array<string, mixed>
     */
    private function trenGabungan(Cakupan $c, ProduksiWidgets $produksi, KeuanganWidgets $keuangan, KeluhanWidgets $keluhan, MutuWidgets $mutu): array
    {
        $porsi = $produksi->trenPorsi();

        return [
            'periode' => $c->periodeTren(),
            'labels' => $porsi['labels'],
            'series' => [
                'porsi_target' => $porsi['series']['target'],
                'porsi_realisasi' => $porsi['series']['realisasi'],
                'porsi_diterima' => $porsi['series']['diterima'],
                'pengeluaran' => $keuangan->deretPengeluaran(),
                'keluhan_masuk' => $c->deret($keluhan->perHari()['total']),
                'mutu_tidak_layak' => $mutu->deretTidakLayak(),
            ],
        ];
    }
}
