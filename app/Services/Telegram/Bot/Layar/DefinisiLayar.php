<?php

namespace App\Services\Telegram\Bot\Layar;

use App\Enums\Role;
use App\Models\User;
use App\Services\Telegram\Bot\BotAksiTulisService;
use App\Services\Telegram\Bot\BotLayarService;

/**
 * Definisi layar baca modul Bot (R02-B1): id, modul RBAC, judul, rute Mini App, dan SEKSI daftar (sumber tool baca
 * katalog + argumen + WHITELIST kolom tampil + pemformat baris). Kolom di luar whitelist tidak pernah mencapai
 * penyaji (BotLayarService memotong payload tool), jadi mis. `uraian` pengeluaran tidak mungkin tampil di chat.
 * Hak sebenarnya tetap dari config/rbac.php lewat BotNavigasi + isAvailableFor tool; modul/hak di sini harus sama
 * dengan matriks BotLayarRegistry::MENU (divalidasi saat registri dibangun).
 *
 * Layar non-daftar (Rekap, Keuangan, Anggaran, Penerima Manfaat agregat, Tim, Profil, Persetujuan Menu) berupa kelas sendiri.
 */
final class DefinisiLayar
{
    /** @return list<LayarBaca> */
    public static function semua(): array
    {
        $layanan = app(BotLayarService::class);
        $daftar = fn (string $id, string $modul, string $judul, array $seksi, ?string $rute = null): LayarDaftar => new LayarDaftar($layanan, $id, $modul, $judul, $seksi, $rute);

        $hariIni = fn (): array => ['dari' => FormatLayar::hariIni(), 'sampai' => FormatLayar::hariIni()];
        $f = FormatLayar::class;

        return [
            // Jadwal produksi dan distribusi hari ini (seksi yang tool-nya tidak tersedia bagi unit relawan dilewati).
            $daftar('jd', 'jadwal', 'Jadwal Hari Ini', [
                new SeksiDaftar('jadwal_produksi', 'Produksi', ['jam_mulai_masak', 'menu', 'target_porsi', 'status'],
                    fn (array $b) => sprintf('%s %s, %s porsi [%s]', $f::jam($b['jam_mulai_masak'] ?? null), $f::nama($b['menu'] ?? null), $f::angka($b['target_porsi'] ?? null), $f::label($b['status'] ?? null)),
                    $hariIni, 'Tidak ada jadwal produksi hari ini.'),
                new SeksiDaftar('jadwal_distribusi', 'Distribusi', ['jam_berangkat', 'jam_rencana_tiba', 'titik_layanan', 'armada', 'pengemudi', 'jumlah_porsi', 'status'],
                    fn (array $b) => sprintf('%s-%s %s, %s porsi, %s (pengemudi %s) [%s]', $f::jam($b['jam_berangkat'] ?? null), $f::jam($b['jam_rencana_tiba'] ?? null), $f::nama($b['titik_layanan'] ?? null), $f::angka($b['jumlah_porsi'] ?? null), $f::nama($b['armada'] ?? null), $f::nama($b['pengemudi'] ?? null), $f::label($b['status'] ?? null)),
                    $hariIni, 'Tidak ada jadwal distribusi hari ini.'),
            ], '/jadwal'),

            // Distribusi hari ini: jadwal + bukti serah terima (tanpa foto/koordinat/catatan).
            $daftar('ds', 'bukti-distribusi', 'Distribusi Hari Ini', [
                new SeksiDaftar('jadwal_distribusi', 'Jadwal', ['jam_berangkat', 'titik_layanan', 'jumlah_porsi', 'status'],
                    fn (array $b) => sprintf('%s %s, %s porsi [%s]', $f::jam($b['jam_berangkat'] ?? null), $f::nama($b['titik_layanan'] ?? null), $f::angka($b['jumlah_porsi'] ?? null), $f::label($b['status'] ?? null)),
                    $hariIni, 'Tidak ada jadwal distribusi hari ini.'),
                new SeksiDaftar('bukti_distribusi', 'Bukti serah terima', ['titik_layanan', 'waktu_tiba', 'porsi_diterima', 'terlambat', 'menit_keterlambatan'],
                    fn (array $b) => sprintf('%s: tiba %s, %s porsi diterima%s', $f::nama($b['titik_layanan'] ?? null), $f::tanggal($b['waktu_tiba'] ?? null), $f::angka($b['porsi_diterima'] ?? null), ($b['terlambat'] ?? false) ? sprintf(', terlambat %s menit', $f::angka($b['menit_keterlambatan'] ?? null)) : ''),
                    $hariIni, 'Belum ada bukti distribusi hari ini.'),
            ], '/bukti-distribusi'),

            // Armada dan pengemudi (plat + status; pengemudi hanya inisial + ID, tanpa SIM/HP).
            $daftar('ar', 'armada', 'Armada', [
                new SeksiDaftar('armada', 'Armada', ['plat_nomor', 'jenis', 'kapasitas_box', 'status'],
                    fn (array $b) => sprintf('%s (%s), %s box [%s]', $f::nama($b['plat_nomor'] ?? null), $f::nama($b['jenis'] ?? null), $f::angka($b['kapasitas_box'] ?? null), $f::label($b['status'] ?? null)),
                    [], 'Belum ada armada.'),
                new SeksiDaftar('pengemudi', 'Pengemudi (inisial)', ['id', 'nama', 'is_active'],
                    fn (array $b) => sprintf('%s #%s [%s]', $f::nama($b['nama'] ?? null), $f::angka($b['id'] ?? null), ($b['is_active'] ?? false) ? 'aktif' : 'nonaktif'),
                    [], 'Belum ada pengemudi.'),
            ], '/armada'),

            // Keluhan terbuka (deskripsi sudah tersamar + terpotong oleh RecordPresenter; di chat dipotong lagi).
            // PR-3: tombol "Proses keluhan #id" (baru -> diproses, berkonfirmasi) hanya untuk peran berhak (BotAksiTulisService).
            new LayarDaftar($layanan, 'kl', 'keluhan', 'Keluhan Terbuka', [
                new SeksiDaftar('keluhan', 'Keluhan', ['id', 'kategori', 'prioritas', 'status', 'titik_layanan', 'deskripsi', 'created_at'],
                    fn (array $b) => sprintf("#%s [%s] %s - %s (%s, %s)\n  %s", $f::angka($b['id'] ?? null), strtoupper((string) ($b['prioritas'] ?? '-')), $f::label($b['kategori'] ?? null), $f::nama($b['titik_layanan'] ?? null), $f::label($b['status'] ?? null), $f::tanggal($b['created_at'] ?? null), $f::teks($b['deskripsi'] ?? null, 100)),
                    ['terbuka' => true], 'Tidak ada keluhan terbuka.'),
            ], '/keluhan', fn (User $u, int $sppg, KonteksLayar $k, array $baris): array => app(BotAksiTulisService::class)->tombolKeluhan($u, $sppg, $k, $baris)),

            // Stok bahan baku.
            $daftar('st', 'bahan-baku', 'Stok Bahan Baku', [
                new SeksiDaftar('bahan_baku', 'Stok', ['kode', 'nama', 'satuan', 'stok', 'stok_minimum', 'kedaluwarsa_terdekat'],
                    fn (array $b) => self::barisStok($b), [], 'Belum ada data bahan baku.'),
            ], '/bahan-baku'),
            $daftar('sm', 'bahan-baku', 'Stok Menipis', [
                new SeksiDaftar('bahan_baku', 'Stok menipis', ['kode', 'nama', 'satuan', 'stok', 'stok_minimum', 'kedaluwarsa_terdekat'],
                    fn (array $b) => self::barisStok($b), ['menipis' => true], 'Tidak ada stok yang menipis.'),
            ], '/bahan-baku/stok-menipis'),
            // `akan_kedaluwarsa` = kedaluwarsa dalam 3 hari ke depan DAN yang sudah lewat (tanggal <= hari ini + 3).
            $daftar('kd', 'bahan-baku', 'Kedaluwarsa', [
                new SeksiDaftar('bahan_baku', 'Kedaluwarsa', ['kode', 'nama', 'satuan', 'stok', 'stok_minimum', 'kedaluwarsa_terdekat'],
                    fn (array $b) => sprintf('%s - kedaluwarsa %s%s', $f::nama($b['nama'] ?? null), $f::hari($b['kedaluwarsa_terdekat'] ?? null), self::lewat($b['kedaluwarsa_terdekat'] ?? null) ? ' [SUDAH LEWAT]' : ''),
                    ['akan_kedaluwarsa' => true], 'Tidak ada bahan yang akan atau sudah kedaluwarsa.'),
            ], '/bahan-baku/kedaluwarsa-dekat'),

            // Pemeriksaan mutu terbaru.
            $daftar('mt', 'mutu', 'Pemeriksaan Mutu', [
                new SeksiDaftar('pemeriksaan_mutu', 'Mutu', ['tanggal_produksi', 'waktu_pemeriksaan', 'suhu_makanan_c', 'hasil'],
                    fn (array $b) => sprintf('%s, suhu %s C: %s', $f::tanggal($b['waktu_pemeriksaan'] ?? null), $f::angka($b['suhu_makanan_c'] ?? null), strtoupper((string) ($b['hasil'] ?? '-'))),
                    [], 'Belum ada pemeriksaan mutu.'),
            ], '/mutu'),

            // Log produksi hari ini; relawan hanya log miliknya (filter milik_saya, aturan bot-saja).
            $daftar('lg', 'log-produksi', 'Log Produksi Hari Ini', [
                new SeksiDaftar('log_produksi', 'Log', ['kode_batch', 'realisasi_porsi', 'sisa_makanan_kg', 'jam_selesai'],
                    fn (array $b) => sprintf('Batch %s: %s porsi, sisa %s kg, selesai %s', $f::nama($b['kode_batch'] ?? null), $f::angka($b['realisasi_porsi'] ?? null), $f::angka($b['sisa_makanan_kg'] ?? null), $f::jam($b['jam_selesai'] ?? null)),
                    fn (User $u) => $hariIni() + ($u->role === Role::Relawan ? ['milik_saya' => true] : []),
                    'Belum ada log produksi hari ini.'),
            ], '/log-produksi'),

            // Titik layanan (PIC inisial, tanpa telepon/alamat).
            $daftar('tl', 'titik-layanan', 'Titik Layanan', [
                new SeksiDaftar('titik_layanan', 'Titik layanan', ['nama', 'jenis', 'jarak_km', 'jumlah_sasaran', 'pic_nama', 'is_active'],
                    fn (array $b) => sprintf('%s (%s), %s km, %s sasaran, PIC %s%s', $f::nama($b['nama'] ?? null), $f::label($b['jenis'] ?? null), $f::angka($b['jarak_km'] ?? null), $f::angka($b['jumlah_sasaran'] ?? null), $f::nama($b['pic_nama'] ?? null), ($b['is_active'] ?? true) ? '' : ' [nonaktif]'),
                    [], 'Belum ada titik layanan.'),
            ], '/titik-layanan'),

            // Notifikasi perjalanan yang sedang berjalan.
            $daftar('np', 'notifikasi-perjalanan', 'Perjalanan Aktif', [
                new SeksiDaftar('notifikasi_perjalanan', 'Perjalanan', ['titik_layanan', 'status_perjalanan', 'status_kirim', 'estimasi_tiba', 'waktu_berangkat'],
                    fn (array $b) => sprintf('%s: %s, estimasi tiba %s (notifikasi %s)', $f::nama($b['titik_layanan'] ?? null), $f::label($b['status_perjalanan'] ?? null), $f::tanggal($b['estimasi_tiba'] ?? null), $f::label($b['status_kirim'] ?? null)),
                    ['aktif' => true], 'Tidak ada perjalanan yang sedang berjalan.'),
            ], '/notifikasi-perjalanan'),

            // Menu dan gizi (terbaru) serta menu hari ini.
            $daftar('mg', 'menu-gizi', 'Menu dan Gizi', [
                new SeksiDaftar('menu', 'Menu', ['nama_menu', 'kelompok_sasaran', 'tanggal_sajian', 'energi_kkal', 'protein_g'],
                    fn (array $b) => self::barisMenu($b), [], 'Belum ada menu.'),
            ], '/menu-gizi'),
            $daftar('mh', 'menu-gizi', 'Menu Hari Ini', [
                new SeksiDaftar('menu', 'Menu', ['nama_menu', 'kelompok_sasaran', 'tanggal_sajian', 'energi_kkal', 'protein_g'],
                    fn (array $b) => self::barisMenu($b), fn () => ['dari' => FormatLayar::hariIni(), 'sampai' => FormatLayar::hariIni()], 'Tidak ada menu untuk hari ini.'),
            ], '/menu-gizi'),

            // Keuangan: pencairan dan pengeluaran. `uraian` pengeluaran TIDAK ada di whitelist (keputusan v1, A7).
            $daftar('pc', 'keuangan', 'Pencairan Dana', [
                new SeksiDaftar('pencairan_dana', 'Pencairan', ['tanggal_cair', 'periode', 'sumber_dana', 'nominal'],
                    fn (array $b) => sprintf('%s: %s (%s, periode %s)', $f::hari($b['tanggal_cair'] ?? null), $f::rupiah($b['nominal'] ?? 0), $f::nama($b['sumber_dana'] ?? null), $f::nama($b['periode'] ?? null)),
                    [], 'Belum ada pencairan dana.'),
            ], '/keuangan'),
            $daftar('pk', 'keuangan', 'Pengeluaran', [
                new SeksiDaftar('pengeluaran', 'Pengeluaran', ['tanggal', 'kategori', 'nominal', 'melebihi_anggaran', 'status_persetujuan'],
                    fn (array $b) => sprintf('%s: %s, %s [%s]%s', $f::hari($b['tanggal'] ?? null), $f::label($b['kategori'] ?? null), $f::rupiah($b['nominal'] ?? 0), $f::label($b['status_persetujuan'] ?? null), ($b['melebihi_anggaran'] ?? false) ? ' melebihi anggaran' : ''),
                    [], 'Belum ada pengeluaran.'),
            ], '/keuangan/pengeluaran'),

            // Pemasok (tanpa kontak/alamat) dan pembelian bahan (pintasan v1; verifikasi tetap di Mini App).
            $daftar('pe', 'pemasok', 'Pemasok', [
                new SeksiDaftar('pemasok', 'Pemasok', ['nama', 'jenis', 'jenis_bahan', 'is_active'],
                    fn (array $b) => sprintf('%s (%s), bahan: %s%s', $f::nama($b['nama'] ?? null), $f::label($b['jenis'] ?? null), $f::nama($b['jenis_bahan'] ?? null), ($b['is_active'] ?? true) ? '' : ' [nonaktif]'),
                    [], 'Belum ada pemasok.'),
            ], '/pemasok'),
            $daftar('pb', 'pembelian-bahan', 'Pembelian Bahan', [
                new SeksiDaftar('pembelian_bahan', 'Pembelian', ['tanggal_beli', 'bahan', 'jumlah', 'satuan', 'total', 'status'],
                    fn (array $b) => sprintf('%s: %s %s %s, %s [%s]', $f::hari($b['tanggal_beli'] ?? null), $f::nama($b['bahan'] ?? null), $f::angka($b['jumlah'] ?? null), $f::nama($b['satuan'] ?? null), $f::rupiah($b['total'] ?? 0), $f::label($b['status'] ?? null)),
                    [], 'Belum ada pembelian bahan.'),
            ], '/pembelian-bahan'),

            // Layar berkelas sendiri.
            app(LayarRekap::class),
            app(LayarKeuangan::class),
            app(LayarAnggaran::class),
            app(LayarAgregatPenerima::class),
            app(LayarTim::class),
            app(LayarProfil::class),
            app(LayarPengajuanMenu::class),
            app(LayarMulaiPerjalanan::class),
            // Petunjuk alur draf (R-02 PR-3 C2): bot tidak menulis; konfirmasi draf di Mini App.
            ...array_map(fn (string $kode): LayarAlurDraf => new LayarAlurDraf($kode), LayarAlurDraf::semuaId()),
        ];
    }

    /** @param array<string, mixed> $b */
    private static function barisStok(array $b): string
    {
        $menipis = isset($b['stok'], $b['stok_minimum']) && (float) $b['stok'] <= (float) $b['stok_minimum'];

        return sprintf(
            '%s: %s %s (min %s)%s%s',
            FormatLayar::nama($b['nama'] ?? null),
            FormatLayar::angka($b['stok'] ?? null),
            FormatLayar::nama($b['satuan'] ?? null),
            FormatLayar::angka($b['stok_minimum'] ?? null),
            $menipis ? ' [MENIPIS]' : '',
            ($b['kedaluwarsa_terdekat'] ?? null) === null ? '' : ', kedaluwarsa '.FormatLayar::hari($b['kedaluwarsa_terdekat']),
        );
    }

    /** @param array<string, mixed> $b */
    private static function barisMenu(array $b): string
    {
        return sprintf(
            '%s %s (%s): %s kkal, protein %s g',
            FormatLayar::hari($b['tanggal_sajian'] ?? null),
            FormatLayar::nama($b['nama_menu'] ?? null),
            FormatLayar::label($b['kelompok_sasaran'] ?? null),
            FormatLayar::angka($b['energi_kkal'] ?? null),
            FormatLayar::angka($b['protein_g'] ?? null),
        );
    }

    private static function lewat(mixed $tanggal): bool
    {
        return is_string($tanggal) && substr($tanggal, 0, 10) < FormatLayar::hariIni();
    }
}
