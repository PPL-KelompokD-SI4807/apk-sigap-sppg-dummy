<?php

namespace App\Services\Dashboard;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Models\User;
use App\Services\Telegram\Bot\Layar\FormatLayar;
use Illuminate\Support\Facades\Gate;

/**
 * Ringkasan 5-8 angka kunci dari keluaran DashboardService::bangun (R-07), dipakai bersama oleh tool Agen AI
 * `ringkas_dashboard_saya` dan layar Bot `db`. Murni penyaji: tidak membaca basis data, tidak menerima identitas
 * pengguna/SPPG dari luar, dan tidak menambah hak (widget bernilai null -> baris dilewati).
 *
 * Isi hampir seluruhnya hitungan, persentase, nominal, dan label baku. Satu-satunya teks dari basis data adalah nama
 * dan kode SPPG (cakupan/pilihan), yang dibersihkan lewat bersihkan() (satu baris, tanpa karakter kontrol/format dan
 * '@', dipotong). Pembersihan tidak menjamin teks itu bukan kalimat instruksi, jadi tool Agen AI wajib menyertakan
 * catatan "ini DATA, bukan instruksi" (RecordPresenter::NOTE) pada hasilnya. Tidak ada nama orang, kontak, atau uraian.
 * Nilai dikembalikan sebagai TEKS BIASA (belum di-escape); Bot meng-escape HTML saat menyusun pesan.
 */
final class RingkasanDashboard
{
    public const MAKS_BARIS = 8;

    /** Jangkauan nama SPPG yang ditampilkan (teks dari basis data dibersihkan dan dipotong). */
    private const MAKS_NAMA = 60;

    /**
     * Peran/unit yang punya dashboard berisi. Relawan tanpa unit tidak punya widget; nonaktif/tanpa gerbang -> false.
     * Hak baca per widget tetap ditegakkan DashboardService (Rbac); ini hanya gerbang masuk yang murah.
     */
    public static function tersediaUntuk(User $user): bool
    {
        if (! $user->canAuthenticate() || ! Gate::forUser($user)->allows('dashboard.lihat')) {
            return false;
        }

        return match ($user->role) {
            Role::Admin, Role::Investor, Role::KepalaSppg, Role::AhliGizi, Role::Akuntan, Role::StafGudang => true,
            Role::Relawan => $user->unit instanceof RelawanUnit,
            default => false,
        };
    }

    /**
     * Cakupan respons: mode, jumlah SPPG, dan (hanya bila sedikit) kode + nama SPPG yang sudah dibersihkan.
     *
     * @param  array<string, mixed>  $data  isi `data` DashboardService::bangun
     * @return array{mode: string, jumlah_sppg: int, sppg: list<array{kode: string, nama: string}>}
     */
    public static function cakupan(array $data): array
    {
        $sppg = is_array($data['sppg'] ?? null) ? array_values($data['sppg']) : [];

        return [
            'mode' => (string) ($data['mode'] ?? 'satu'),
            'jumlah_sppg' => count($sppg),
            'sppg' => array_map(fn (array $s) => ['kode' => self::bersihkan((string) ($s['kode'] ?? '')), 'nama' => self::bersihkan((string) ($s['nama'] ?? ''))], array_slice($sppg, 0, 5)),
        ];
    }

    /**
     * @param  array<string, mixed>  $data  isi `data` DashboardService::bangun
     * @return list<array{kunci: string, label: string, nilai: string}>
     */
    public static function dari(array $data): array
    {
        $w = is_array($data['widgets'] ?? null) ? $data['widgets'] : [];

        $baris = match ((string) ($data['profil'] ?? '')) {
            'investor' => self::investor($w),
            'kepala_sppg' => self::kepala($w),
            'ahli_gizi' => self::ahliGizi($w),
            'akuntan' => self::akuntan($w),
            'staf_gudang' => self::gudang($w),
            'relawan_dapur' => self::relawanDapur($w),
            'relawan_distribusi' => self::relawanDistribusi($w),
            'admin' => self::admin($w),
            default => [],
        };

        return array_slice(array_values(array_filter($baris)), 0, self::MAKS_BARIS);
    }

    /** Teks dari basis data -> satu baris aman: tanpa karakter kontrol/format, tanpa '@', dipotong. */
    public static function bersihkan(string $teks): string
    {
        $bersih = (string) preg_replace('/[\p{C}\p{Z}]+/u', ' ', str_replace('@', '', $teks));
        $bersih = trim($bersih);

        return mb_strlen($bersih) > self::MAKS_NAMA ? rtrim(mb_substr($bersih, 0, self::MAKS_NAMA)).'…' : $bersih;
    }

    // ------------------------------------------------------------------ per profil

    /** @param array<string, mixed> $w */
    private static function investor(array $w): array
    {
        return [
            self::porsi($w['porsi_hari_ini'] ?? null, 'porsi_hari_ini', 'Porsi hari ini'),
            self::anggaran($w['anggaran'] ?? null),
            self::pengajuan($w['pengajuan_menunggu'] ?? null),
            self::stokKritis($w['stok_kritis'] ?? null),
            self::insidenMutu($w['insiden_mutu'] ?? null),
            self::keluhanKritis($w['keluhan_kritis'] ?? null),
            self::buktiNota($w['bukti_nota'] ?? null),
        ];
    }

    /** @param array<string, mixed> $w */
    private static function kepala(array $w): array
    {
        $jadwal = $w['jadwal_produksi_hari_ini'] ?? null;
        $titik = $w['distribusi_per_titik']['items'] ?? null;
        $keluhan = $w['keluhan_terbuka'] ?? null;
        $mutu = $w['mutu']['hari_ini'] ?? null;
        $rekap = $w['rekap_harian'] ?? null;

        return [
            is_array($jadwal) ? self::b('jadwal_produksi', 'Jadwal produksi hari ini', sprintf('%s aktif dari %s jadwal, target %s porsi', self::n($jadwal['aktif'] ?? 0), self::n($jadwal['total'] ?? 0), self::n($jadwal['target_porsi'] ?? 0))) : null,
            self::porsi($w['porsi_target_vs_realisasi'] ?? null, 'porsi_hari_ini', 'Porsi hari ini'),
            is_array($titik) ? self::b('distribusi', 'Distribusi hari ini', sprintf(
                '%s dari %s jadwal selesai, %s terlambat',
                self::n(array_sum(array_column($titik, 'jadwal_selesai'))),
                self::n(array_sum(array_column($titik, 'jumlah_jadwal'))),
                self::n(array_sum(array_column($titik, 'terlambat'))),
            )) : null,
            is_array($mutu) ? self::b('mutu_hari_ini', 'Mutu hari ini', sprintf('%s diperiksa, %s tidak layak, %s batch belum diperiksa', self::n($mutu['pemeriksaan'] ?? 0), self::n($mutu['tidak_layak'] ?? 0), self::n($mutu['batch_belum_diperiksa'] ?? 0))) : null,
            is_array($keluhan) ? self::b('keluhan_terbuka', 'Keluhan terbuka', sprintf('%s (%s kritis)', self::n($keluhan['terbuka'] ?? 0), self::n($keluhan['kritis_terbuka'] ?? 0))) : null,
            self::stokKritis($w['stok_kritis'] ?? null),
            self::anggaran($w['anggaran'] ?? null),
            is_array($rekap) ? self::b('rekap_harian', 'Rekap harian', sprintf('%s, status kirim: %s', ($rekap['sudah_disusun'] ?? false) ? 'sudah disusun' : 'belum disusun', FormatLayar::label($rekap['status_kirim'] ?? null))) : null,
        ];
    }

    /** @param array<string, mixed> $w */
    private static function ahliGizi(array $w): array
    {
        $menu = $w['menu_hari_ini'] ?? null;
        $gizi = $w['gizi_vs_standar'] ?? null;
        $biaya = $w['biaya_per_porsi'] ?? null;
        $khusus = $w['kebutuhan_khusus'] ?? null;
        $mutu = $w['hasil_mutu'] ?? null;

        return [
            is_array($menu) ? self::b('menu_hari_ini', 'Menu hari ini', sprintf('%s menu (%s disetujui, %s masih draf)', self::n($menu['jumlah'] ?? 0), self::n(self::cari($menu['per_status'] ?? [], 'status', 'disetujui', 'jumlah')), self::n(self::cari($menu['per_status'] ?? [], 'status', 'draf', 'jumlah')))) : null,
            is_array($gizi) ? self::b('gizi_vs_standar', 'Kesesuaian gizi', sprintf('%s sesuai, %s tidak sesuai, %s tanpa standar', self::n($gizi['sesuai'] ?? 0), self::n($gizi['tidak_sesuai'] ?? 0), self::n($gizi['tanpa_standar'] ?? 0))) : null,
            is_array($biaya) ? self::b('biaya_per_porsi', 'Biaya per porsi rata-rata', sprintf('%s (%s menu belum ada biaya)', ($biaya['rata_rata'] ?? null) === null ? '-' : FormatLayar::rupiah($biaya['rata_rata']), self::n($biaya['menu_tanpa_biaya'] ?? 0))) : null,
            is_array($khusus) ? self::b('kebutuhan_khusus', 'Penerima dengan catatan alergi', ($khusus['total'] ?? null) === null ? 'disamarkan (kelompok kecil)' : self::n($khusus['total'])) : null,
            is_array($mutu) ? self::b('mutu_hari_ini', 'Mutu hari ini', sprintf('%s diperiksa, %s tidak layak', self::n($mutu['hari_ini']['pemeriksaan'] ?? 0), self::n($mutu['hari_ini']['tidak_layak'] ?? 0))) : null,
            is_array($mutu) ? self::b('mutu_layak_bulan', 'Persen layak bulan ini', self::persen($mutu['persen_layak_bulan'] ?? null)) : null,
        ];
    }

    /** @param array<string, mixed> $w */
    private static function akuntan(array $w): array
    {
        $cair = $w['pencairan_vs_pengeluaran'] ?? null;
        $menunggu = $w['pengeluaran_menunggu'] ?? null;
        $kategori = $w['serapan_per_kategori']['items'] ?? null;
        $terbesar = null;

        foreach (is_array($kategori) ? $kategori : [] as $k) {
            if (($k['nominal'] ?? 0) > ($terbesar['nominal'] ?? 0)) {
                $terbesar = $k;
            }
        }

        return [
            is_array($cair) ? self::b('pencairan', 'Pencairan bulan ini', FormatLayar::rupiah($cair['pencairan'] ?? 0)) : null,
            is_array($cair) ? self::b('pengeluaran', 'Pengeluaran disetujui bulan ini', FormatLayar::rupiah($cair['pengeluaran'] ?? 0)) : null,
            is_array($cair) ? self::b('selisih', 'Selisih', sprintf('%s (serapan %s)', FormatLayar::rupiah($cair['selisih'] ?? 0), self::persen($cair['persen'] ?? null))) : null,
            is_array($menunggu) ? self::b('pengeluaran_menunggu', 'Menunggu persetujuan investor', sprintf('%s pengeluaran (%s)', self::n($menunggu['pengeluaran']['jumlah'] ?? 0), FormatLayar::rupiah($menunggu['pengeluaran']['nominal'] ?? 0))) : null,
            is_array($menunggu) ? self::b('pembelian_menunggu', 'Pembelian menunggu verifikasi', self::n($menunggu['pembelian_menunggu_verifikasi']['jumlah'] ?? 0)) : null,
            self::buktiNota($w['bukti_nota'] ?? null),
            $terbesar === null ? null : self::b('kategori_terbesar', 'Kategori pengeluaran terbesar', sprintf('%s (%s)', FormatLayar::label($terbesar['kategori'] ?? null), FormatLayar::rupiah($terbesar['nominal'] ?? 0))),
        ];
    }

    /** @param array<string, mixed> $w */
    private static function gudang(array $w): array
    {
        $bahan = $w['stok_per_bahan'] ?? null;
        $min = $w['stok_di_bawah_minimum'] ?? null;
        $exp = $w['stok_mendekati_kedaluwarsa'] ?? null;
        $mutasi = $w['mutasi_hari_ini'] ?? null;
        $kirim = $w['pengiriman_pemasok'] ?? null;

        return [
            is_array($bahan) ? self::b('jenis_bahan', 'Jenis bahan baku', self::n($bahan['total_bahan'] ?? 0)) : null,
            is_array($min) ? self::b('di_bawah_minimum', 'Stok di bawah minimum', self::n($min['jumlah'] ?? 0)) : null,
            is_array($exp) ? self::b('kedaluwarsa', 'Kedaluwarsa', sprintf('%s sudah lewat, %s dalam %s hari', self::n($exp['sudah_kedaluwarsa'] ?? 0), self::n($exp['akan_kedaluwarsa'] ?? 0), self::n($exp['hari_peringatan'] ?? 0))) : null,
            is_array($mutasi) ? self::b('mutasi_hari_ini', 'Mutasi stok hari ini', sprintf(
                '%s (%s masuk, %s keluar, %s penyesuaian)',
                self::n($mutasi['total'] ?? 0),
                self::n(self::cari($mutasi['per_jenis'] ?? [], 'jenis', 'masuk', 'jumlah')),
                self::n(self::cari($mutasi['per_jenis'] ?? [], 'jenis', 'keluar', 'jumlah')),
                self::n(self::cari($mutasi['per_jenis'] ?? [], 'jenis', 'penyesuaian', 'jumlah')),
            )) : null,
            is_array($kirim) ? self::b('penerimaan_pemasok', 'Penerimaan dari pemasok hari ini', sprintf('%s penerimaan dari %s pemasok', self::n($kirim['penerimaan_hari_ini'] ?? 0), self::n($kirim['pemasok_hari_ini'] ?? 0))) : null,
            is_array($kirim['pembelian'] ?? null) ? self::b('pembelian_menunggu', 'Pembelian menunggu verifikasi', self::n($kirim['pembelian']['menunggu_verifikasi'] ?? 0)) : null,
        ];
    }

    /** @param array<string, mixed> $w */
    private static function relawanDapur(array $w): array
    {
        $menu = $w['menu_target_hari_ini'] ?? null;
        $log = $w['status_log_produksi'] ?? null;
        $cek = $w['checklist_mutu_belum_diisi'] ?? null;

        return [
            is_array($menu) ? self::b('jadwal_masak', 'Jadwal masak hari ini', sprintf('%s jadwal, target %s porsi', self::n($menu['jumlah_jadwal'] ?? 0), self::n($menu['total_target_porsi'] ?? 0))) : null,
            is_array($log) ? self::b('log_produksi', 'Log produksi hari ini', sprintf('%s sudah diisi, %s belum', self::n($log['sudah_ada_log'] ?? 0), self::n($log['belum_ada_log'] ?? 0))) : null,
            is_array($cek) ? self::b('checklist_mutu', 'Checklist mutu belum diisi', self::n($cek['jumlah'] ?? 0)) : null,
            is_array($menu) ? self::b('status_jadwal', 'Status jadwal masak', self::ringkasStatus($menu['items'] ?? [], (int) ($menu['jumlah_jadwal'] ?? 0), 'status')) : null,
            is_array($log) && ($tercatat = self::jumlahLengkap($log['items'] ?? [], (int) ($log['jumlah_jadwal'] ?? 0), 'realisasi_porsi')) !== null
                ? self::b('porsi_tercatat', 'Porsi tercatat di log', self::n($tercatat)) : null,
        ];
    }

    /** @param array<string, mixed> $w */
    private static function relawanDistribusi(array $w): array
    {
        $rute = $w['rute_hari_ini'] ?? null;
        $kirim = $w['status_kirim'] ?? null;
        $bukti = $w['bukti_belum_diunggah'] ?? null;
        $armada = $w['armada_pengemudi'] ?? null;

        $status = [];

        foreach (is_array($kirim) ? ($kirim['per_status'] ?? []) : [] as $s) {
            if (($s['jumlah'] ?? 0) > 0) {
                $status[] = self::n($s['jumlah']).' '.mb_strtolower(FormatLayar::label($s['status'] ?? null));
            }
        }

        return [
            is_array($rute) ? self::b('titik_hari_ini', 'Titik tujuan hari ini', self::n($rute['jumlah_titik'] ?? 0)) : null,
            is_array($kirim) ? self::b('status_kirim', 'Status pengiriman', $status === [] ? 'belum ada pengiriman' : implode(', ', $status)) : null,
            is_array($bukti) ? self::b('bukti_belum', 'Bukti serah terima belum diunggah', self::n($bukti['jumlah'] ?? 0)) : null,
            is_array($rute) && ($porsi = self::jumlahLengkap($rute['items'] ?? [], (int) ($rute['jumlah_titik'] ?? 0), 'jumlah_porsi')) !== null
                ? self::b('porsi_dikirim', 'Porsi dijadwalkan dikirim hari ini', self::n($porsi)) : null,
            is_array($armada) ? self::b('armada', 'Armada', sprintf(
                '%s tersedia, %s bertugas, %s servis',
                self::n(self::cari($armada['armada_per_status'] ?? [], 'status', 'tersedia', 'jumlah')),
                self::n(self::cari($armada['armada_per_status'] ?? [], 'status', 'bertugas', 'jumlah')),
                self::n(self::cari($armada['armada_per_status'] ?? [], 'status', 'servis', 'jumlah')),
            )) : null,
        ];
    }

    /** @param array<string, mixed> $w */
    private static function admin(array $w): array
    {
        $o = $w['operasional_lintas_sppg'] ?? null;
        $total = is_array($o) ? ($o['total'] ?? []) : [];
        $pengguna = $w['pengguna_aktif_per_peran'] ?? null;
        $login = $w['login_gagal'] ?? null;
        $ai = $w['draf_ai_tertunda'] ?? null;

        return [
            is_array($o) ? self::b('jumlah_sppg', 'SPPG dicakup', self::n($o['jumlah_sppg'] ?? 0)) : null,
            is_array($o) ? self::b('porsi_hari_ini', 'Porsi hari ini', sprintf('%s dari %s (%s)', self::n($total['realisasi_porsi'] ?? 0), self::n($total['target_porsi'] ?? 0), self::persen($total['capaian_persen'] ?? null))) : null,
            is_array($o) ? self::b('distribusi', 'Distribusi hari ini', sprintf('%s dari %s jadwal selesai', self::n($total['distribusi_selesai'] ?? 0), self::n($total['distribusi_jadwal'] ?? 0))) : null,
            is_array($o) ? self::b('keluhan_terbuka', 'Keluhan terbuka', sprintf('%s (%s kritis)', self::n($total['keluhan_terbuka'] ?? 0), self::n($total['keluhan_kritis'] ?? 0))) : null,
            is_array($o) ? self::b('di_bawah_minimum', 'Stok di bawah minimum', self::n($total['stok_di_bawah_minimum'] ?? 0)) : null,
            is_array($pengguna) ? self::b('pengguna_aktif', 'Pengguna aktif', self::n($pengguna['total_aktif'] ?? 0)) : null,
            is_array($login) ? self::b('login_gagal', 'Login gagal hari ini', sprintf('%s gagal, %s akun terkunci saat ini', self::n($login['hari_ini']['masuk_gagal'] ?? 0), self::n($login['akun_terkunci_saat_ini'] ?? 0))) : null,
            is_array($ai) ? self::b('draf_ai', 'Draf Agen AI menunggu konfirmasi', self::n($ai['menunggu_konfirmasi'] ?? 0)) : null,
        ];
    }

    // ------------------------------------------------------------------ baris bersama

    private static function porsi(mixed $p, string $kunci, string $label): ?array
    {
        if (! is_array($p)) {
            return null;
        }

        return self::b($kunci, $label, sprintf(
            '%s dari %s porsi (%s), %s diterima',
            self::n($p['realisasi'] ?? 0),
            self::n($p['target'] ?? 0),
            self::persen($p['capaian_persen'] ?? null),
            self::n($p['diterima'] ?? 0),
        ));
    }

    private static function anggaran(mixed $a): ?array
    {
        if (! is_array($a)) {
            return null;
        }

        $ambang = (int) ($a['penanda']['ambang_peringatan_persen'] ?? 80);
        $status = match ($a['status'] ?? null) {
            'belum_ada_anggaran' => 'belum ada anggaran',
            'peringatan' => "PERINGATAN: mencapai {$ambang}%",
            'habis' => 'HABIS: mencapai 100%',
            'melampaui' => 'MELAMPAUI anggaran',
            default => 'aman',
        };

        return self::b('anggaran', 'Serapan anggaran bulan ini', sprintf(
            '%s dari %s (%s), %s',
            FormatLayar::rupiah($a['terpakai'] ?? 0),
            FormatLayar::rupiah($a['anggaran'] ?? 0),
            self::persen($a['persen'] ?? null),
            $status,
        ));
    }

    private static function pengajuan(mixed $p): ?array
    {
        if (! is_array($p)) {
            return null;
        }

        return self::b('pengajuan_menunggu', 'Menunggu keputusan investor', sprintf(
            '%s pengajuan (%s pengeluaran senilai %s, %s menu)',
            self::n($p['total'] ?? 0),
            self::n($p['pengeluaran']['jumlah'] ?? 0),
            FormatLayar::rupiah($p['pengeluaran']['nominal'] ?? 0),
            self::n($p['menu']['jumlah'] ?? 0),
        ));
    }

    private static function stokKritis(mixed $s): ?array
    {
        if (! is_array($s)) {
            return null;
        }

        return self::b('stok_kritis', 'Stok kritis', sprintf(
            '%s di bawah minimum, %s sudah kedaluwarsa, %s segera kedaluwarsa',
            self::n($s['di_bawah_minimum'] ?? 0),
            self::n($s['sudah_kedaluwarsa'] ?? 0),
            self::n($s['akan_kedaluwarsa'] ?? 0),
        ));
    }

    private static function insidenMutu(mixed $m): ?array
    {
        if (! is_array($m)) {
            return null;
        }

        return self::b('mutu_tidak_layak', 'Pemeriksaan mutu tidak layak', sprintf('%s hari ini, %s bulan ini', self::n($m['tidak_layak_hari_ini'] ?? 0), self::n($m['tidak_layak_bulan'] ?? 0)));
    }

    private static function keluhanKritis(mixed $k): ?array
    {
        if (! is_array($k)) {
            return null;
        }

        return self::b('keluhan_kritis', 'Keluhan kritis terbuka', sprintf('%s (%s terbuka semua prioritas)', self::n($k['kritis_terbuka'] ?? 0), self::n($k['terbuka_semua_prioritas'] ?? 0)));
    }

    private static function buktiNota(mixed $b): ?array
    {
        if (! is_array($b)) {
            return null;
        }

        return self::b('bukti_nota', 'Pengeluaran tanpa bukti nota', sprintf('%s dari %s (%s)', self::n($b['bukti_belum_ada']['jumlah'] ?? 0), self::n($b['pengeluaran_dihitung'] ?? 0), self::persen($b['persen_bukti_belum_ada'] ?? null)));
    }

    // ------------------------------------------------------------------ pembantu

    /** @return array{kunci: string, label: string, nilai: string} */
    private static function b(string $kunci, string $label, string|int|float $nilai): array
    {
        return ['kunci' => $kunci, 'label' => $label, 'nilai' => (string) $nilai];
    }

    private static function n(mixed $nilai): string
    {
        return FormatLayar::angka($nilai);
    }

    private static function persen(mixed $nilai): string
    {
        return $nilai === null ? '-' : FormatLayar::angka($nilai, 1).'%';
    }

    /**
     * Jumlahkan kolom numerik $kolom hanya bila daftar memuat SEMUA baris ($total); daftar dashboard dibatasi sehingga
     * penjumlahan sebagian akan menyesatkan (dikembalikan null = baris dilewati).
     */
    private static function jumlahLengkap(mixed $items, int $total, string $kolom): int|float|null
    {
        if (! is_array($items) || count($items) !== $total) {
            return null;
        }

        return array_sum(array_map(fn (mixed $i) => is_array($i) && is_numeric($i[$kolom] ?? null) ? $i[$kolom] : 0, $items));
    }

    /**
     * "1 selesai, 1 dimasak" dari daftar bersatus (label baku), hanya bila daftar lengkap; selain itu '-'.
     */
    private static function ringkasStatus(mixed $items, int $total, string $kolom): string
    {
        if (! is_array($items) || $items === [] || count($items) !== $total) {
            return $total === 0 ? 'belum ada jadwal' : '-';
        }

        $hitung = [];

        foreach ($items as $i) {
            $label = mb_strtolower(FormatLayar::label(is_array($i) ? ($i[$kolom] ?? null) : null));
            $hitung[$label] = ($hitung[$label] ?? 0) + 1;
        }

        return implode(', ', array_map(fn (string $label, int $n) => self::n($n).' '.$label, array_keys($hitung), $hitung));
    }

    /**
     * Ambil kolom $kolom dari baris pertama di $daftar yang $kunci-nya sama dengan $nilai.
     */
    private static function cari(mixed $daftar, string $kunci, string $nilai, string $kolom): int|float
    {
        foreach (is_array($daftar) ? $daftar : [] as $baris) {
            if (is_array($baris) && ($baris[$kunci] ?? null) === $nilai) {
                return $baris[$kolom] ?? 0;
            }
        }

        return 0;
    }
}
