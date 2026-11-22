<?php

namespace App\Support;

use App\Enums\JenisLaporanBgn;
use App\Models\BahanBaku;
use App\Models\FeedbackKeluhan;
use App\Models\JadwalProduksi;
use App\Models\LaporanBgn;
use App\Models\Menu;
use App\Models\NotifikasiPerjalanan;
use App\Models\Pemasok;
use App\Models\RekapHarian;
use App\Models\TitikLayanan;
use App\Models\User;
use App\Services\Dashboard\DashboardService;
use App\Services\Dashboard\RingkasanDashboard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Peta hak tambahan untuk klien (Mini App/bot) di atas `modules`/`permissions` (Rbac): `unit_scope`, `aksi`, dan
 * `izin` per record. TIDAK ADA aturan baru di sini: setiap nilai adalah hasil Rbac/Policy/Gate yang SAMA dengan yang
 * menegakkan endpoint (server tetap menolak 403). Aksi yang bergantung pada record dievaluasi pada record "probe"
 * (model kosong di SPPG pengguna) sehingga hasilnya sama dengan Policy untuk record SPPG sendiri. Klien yang tidak
 * menerima peta ini wajib gagal tertutup (tidak menampilkan menu/tombol aksi). Lihat docs/api/peta-hak.md.
 */
final class PetaHak
{
    /** Urutan tetap kunci `aksi` (selain `dashboard`). */
    public const KUNCI_AKSI = [
        'undang_pengguna',
        'reset_password_pengguna',
        'kelola_kepemilikan_sppg',
        'undang_pic',
        'ajukan_menu',
        'lihat_rincian_biaya',
        'picu_perjalanan',
        'susun_rekap',
        'kirim_ulang_rekap',
        'tindak_lanjut_keluhan',
        'catat_mutasi_stok',
        'catat_harga_pemasok',
        'lihat_ringkasan_jadwal_produksi',
        'lihat_mutasi_stok',
        'lihat_laporan_bgn',
        'lihat_laporan_bgn_agregat',
        'terbitkan_nomor_laporan_bgn',
    ];

    /**
     * Peta modul -> sub-jenis data yang boleh dikelola (Rbac::unitScope), hanya untuk modul yang masih punya hak.
     * Mis. relawan dapur: ['jadwal' => 'produksi']. Peran tanpa pembatasan sub-jenis: [].
     *
     * @return array<string, string>
     */
    public static function unitScope(?User $user): array
    {
        $hasil = [];

        foreach (Rbac::allowedModules($user) as $modul) {
            $scope = Rbac::unitScope($user, $modul);

            if ($scope !== null && $scope !== '__none__') {
                $hasil[$modul] = $scope;
            }
        }

        return $hasil;
    }

    /**
     * Aksi di luar CRUD. Semua `false` untuk pengguna null/nonaktif (fail closed).
     *
     * @return array<string, mixed> kunci = KUNCI_AKSI (bool) + `dashboard` {tersedia: bool, profil: ?string}
     */
    public static function aksi(?User $user): array
    {
        $hasil = array_fill_keys(self::KUNCI_AKSI, false) + ['dashboard' => ['tersedia' => false, 'profil' => null]];

        if ($user === null || ! $user->canAuthenticate()) {
            return $hasil;
        }

        $gate = Gate::forUser($user);
        $sppg = $user->sppgAktifId() ?? $user->sppg_id;
        $probe = static fn (string $kelas): Model => (new $kelas)->forceFill(['sppg_id' => $sppg]);

        $hasil['undang_pengguna'] = $gate->allows('create', User::class);
        $hasil['reset_password_pengguna'] = $gate->allows('update', $probe(User::class));
        $hasil['kelola_kepemilikan_sppg'] = $gate->allows('kelolaKepemilikan', $probe(User::class));
        $hasil['undang_pic'] = $gate->allows('update', $probe(TitikLayanan::class));
        $hasil['ajukan_menu'] = $gate->allows('ajukan', $probe(Menu::class));
        $hasil['lihat_rincian_biaya'] = $gate->allows('lihatRincianBiaya', $probe(Menu::class));
        $hasil['picu_perjalanan'] = $gate->allows('create', NotifikasiPerjalanan::class);
        $hasil['susun_rekap'] = $gate->allows('create', RekapHarian::class);
        $hasil['kirim_ulang_rekap'] = $gate->allows('update', $probe(RekapHarian::class));
        $hasil['tindak_lanjut_keluhan'] = $gate->allows('update', $probe(FeedbackKeluhan::class));
        $hasil['catat_mutasi_stok'] = $gate->allows('update', $probe(BahanBaku::class));
        $hasil['catat_harga_pemasok'] = $gate->allows('catatHarga', $probe(Pemasok::class));
        $hasil['lihat_ringkasan_jadwal_produksi'] = $gate->allows('viewRingkas', JadwalProduksi::class);
        $hasil['lihat_mutasi_stok'] = $gate->allows('lihatMutasi', $probe(BahanBaku::class));

        // Laporan BGN (R07): LaporanBgnPolicy bergerbang per jenis + SPPG. Probe: 30b (non-agregat, bernomor) untuk hak penuh,
        // 30a (agregat) untuk hak agregat (investor), SPPG aktif pengguna; sama dengan LaporanBgnRequest::authorize.
        $sppgLaporan = (int) ($user->sppgAktifId() ?? 0);
        $hasil['lihat_laporan_bgn'] = $gate->allows('lihat', [LaporanBgn::class, JenisLaporanBgn::Harian, $sppgLaporan]);
        $hasil['lihat_laporan_bgn_agregat'] = $gate->allows('lihat', [LaporanBgn::class, JenisLaporanBgn::Porsi, $sppgLaporan]);
        $hasil['terbitkan_nomor_laporan_bgn'] = $gate->allows('terbitkanNomor', [LaporanBgn::class, JenisLaporanBgn::Harian, $sppgLaporan]);

        $tersedia = RingkasanDashboard::tersediaUntuk($user);
        $hasil['dashboard'] = ['tersedia' => $tersedia, 'profil' => $tersedia ? DashboardService::profilDari($user) : null];

        return $hasil;
    }

    /**
     * Izin per record: kunci => hasil Gate/Policy untuk record itu. `$kemampuan` = kunci => nama ability Policy.
     * Tidak membuat query (Policy hanya membaca atribut record dan peta hak yang di-cache per permintaan);
     * pemanggil wajib sudah memuat atribut/relasi yang dibutuhkan Policy. Pengguna null -> semua false.
     *
     * @param  array<string, string>  $kemampuan
     * @return array<string, bool>
     */
    public static function izin(?User $user, Model $record, array $kemampuan): array
    {
        $gate = $user === null ? null : Gate::forUser($user);

        return array_map(
            static fn (string $ability): bool => $gate !== null && $gate->allows($ability, $record),
            $kemampuan,
        );
    }
}
