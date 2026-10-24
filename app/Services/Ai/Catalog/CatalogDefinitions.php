<?php

namespace App\Services\Ai\Catalog;

use App\Enums\HasilMutu;
use App\Enums\JenisPemasok;
use App\Enums\JenisTitikLayanan;
use App\Enums\KategoriBahan;
use App\Enums\KategoriKeluhan;
use App\Enums\KategoriPengeluaran;
use App\Enums\KelompokSasaran;
use App\Enums\PrioritasKeluhan;
use App\Enums\Role;
use App\Enums\StatusArmada;
use App\Enums\StatusJadwalDistribusi;
use App\Enums\StatusJadwalProduksi;
use App\Enums\StatusKeluhan;
use App\Enums\StatusKirim;
use App\Enums\StatusOperasional;
use App\Enums\StatusPerjalanan;
use App\Http\Requests\Armada\StoreArmadaRequest;
use App\Http\Requests\Armada\StorePengemudiRequest;
use App\Http\Requests\Armada\UpdateArmadaRequest;
use App\Http\Requests\Armada\UpdatePengemudiRequest;
use App\Http\Requests\BahanBaku\StoreBahanBakuRequest;
use App\Http\Requests\BahanBaku\StoreMutasiStokRequest;
use App\Http\Requests\BahanBaku\UpdateBahanBakuRequest;
use App\Http\Requests\BuktiDistribusi\StoreBuktiDistribusiRequest;
use App\Http\Requests\BuktiDistribusi\UpdateBuktiDistribusiRequest;
use App\Http\Requests\Jadwal\StoreJadwalDistribusiRequest;
use App\Http\Requests\Jadwal\StoreJadwalProduksiRequest;
use App\Http\Requests\Jadwal\UpdateJadwalDistribusiRequest;
use App\Http\Requests\Jadwal\UpdateJadwalProduksiRequest;
use App\Http\Requests\Keluhan\StoreKeluhanRequest;
use App\Http\Requests\Keluhan\UpdateKeluhanRequest;
use App\Http\Requests\Keuangan\StorePencairanRequest;
use App\Http\Requests\Keuangan\StorePengeluaranRequest;
use App\Http\Requests\Keuangan\UpdatePencairanRequest;
use App\Http\Requests\Keuangan\UpdatePengeluaranRequest;
use App\Http\Requests\LogProduksi\StoreLogProduksiRequest;
use App\Http\Requests\LogProduksi\UpdateLogProduksiRequest;
use App\Http\Requests\MenuGizi\StoreMenuRequest;
use App\Http\Requests\MenuGizi\StoreStandarGiziRequest;
use App\Http\Requests\MenuGizi\UpdateMenuRequest;
use App\Http\Requests\Mutu\StorePemeriksaanMutuRequest;
use App\Http\Requests\Mutu\UpdatePemeriksaanMutuRequest;
use App\Http\Requests\Pemasok\StorePemasokRequest;
use App\Http\Requests\Pemasok\UpdatePemasokRequest;
use App\Http\Requests\PenerimaManfaat\StorePenerimaManfaatRequest;
use App\Http\Requests\PenerimaManfaat\UpdatePenerimaManfaatRequest;
use App\Http\Requests\Perjalanan\StoreNotifikasiPerjalananRequest;
use App\Http\Requests\Rekap\StoreRekapHarianRequest;
use App\Http\Requests\Rekap\UpdateRekapHarianRequest;
use App\Http\Requests\Sppg\UpdateSppgRequest;
use App\Http\Requests\TitikLayanan\StoreTitikLayananRequest;
use App\Http\Requests\TitikLayanan\UpdateTitikLayananRequest;
use App\Models\Armada;
use App\Models\BahanBaku;
use App\Models\BuktiDistribusi;
use App\Models\FeedbackKeluhan;
use App\Models\JadwalDistribusi;
use App\Models\JadwalProduksi;
use App\Models\LogProduksi;
use App\Models\Menu;
use App\Models\NotifikasiPerjalanan;
use App\Models\Pemasok;
use App\Models\PemeriksaanMutu;
use App\Models\PencairanDana;
use App\Models\PenerimaManfaat;
use App\Models\Pengeluaran;
use App\Models\Pengemudi;
use App\Models\RekapHarian;
use App\Models\Sppg;
use App\Models\StandarGizi;
use App\Models\TitikLayanan;
use App\Models\User;
use BackedEnum;

/**
 * Definisi deklaratif tool Agen AI untuk 16 modul (urutan resmi CLAUDE.md). Satu ResourceSpec per
 * sumber daya; ToolCatalog membangun cari_/buat_/ubah_/hapus_ darinya.
 *
 * Aturan keamanan (diuji di tests/Feature/Ai/ToolKatalogTest):
 * - Kolom baca TIDAK PERNAH memuat data sensitif (no_sim, no_hp, telepon, telegram_*, NIK, alergi, koordinat,
 *   jalur berkas): RecordPresenter::assertSafe() menolaknya saat tool dibangun.
 * - Nama orang (anak penerima manfaat, PIC, pengemudi) disajikan sebagai inisial + ID ($initials);
 *   penerima manfaat juga tanpa sekolah/kelas. Nama lengkap hanya tampil di Mini App.
 * - Kolom form sensitif dikecualikan dari skema tulis ($exclude): pengguna mengisinya sendiri di Mini App.
 * - Ketersediaan tool = matriks Tabel 5 + aturan unit relawan + Policy (BaseAiTool); sppg_id/user_id/role
 *   tidak pernah ditawarkan dan selalu dibuang (config ai.protected_arguments).
 * - Pengguna (modul 1) hanya dibaca: akun dan peran tidak dibuat/diubah lewat draf AI.
 */
final class CatalogDefinitions
{
    /** @return list<ResourceSpec> */
    public static function all(): array
    {
        return [
            // 1. Data Pengguna (Login Akun): baca saja; tanpa telepon/telegram.
            new ResourceSpec(
                key: 'pengguna', module: 'pengguna', label: 'pengguna', model: User::class, actions: ['R'],
                search: ['name'],
                list: ['id', 'name', 'role', 'unit', 'is_active'],
                filters: [
                    'role' => ['type' => 'string', 'enum' => self::values(Role::class), 'description' => 'Filter peran.'],
                    'is_active' => ['type' => 'boolean', 'description' => 'Filter status aktif.'],
                ],
                order: [['name', 'asc'], ['id', 'asc']],
            ),

            // 2. Notifikasi Perjalanan Mobil ke Tujuan.
            new ResourceSpec(
                key: 'notifikasi_perjalanan', module: 'notifikasi-perjalanan', label: 'notifikasi perjalanan mobil', model: NotifikasiPerjalanan::class,
                actions: ['R', 'C'],
                list: ['id', 'jadwal_distribusi_id', 'titik_layanan' => 'titikLayanan.nama', 'status_perjalanan', 'status_kirim', 'estimasi_tiba', 'waktu_berangkat', 'waktu_tiba'],
                filters: [
                    'status_perjalanan' => ['type' => 'string', 'enum' => self::values(StatusPerjalanan::class)],
                    'status_kirim' => ['type' => 'string', 'enum' => self::values(StatusKirim::class)],
                    'aktif' => ['type' => 'boolean', 'scope' => 'aktif', 'description' => 'Hanya perjalanan yang sedang berjalan.'],
                ],
                date: 'created_at',
                store: StoreNotifikasiPerjalananRequest::class,
            ),

            // 3. Data SPPG: scope SPPG = id SPPG itu sendiri; telepon tidak disajikan.
            new ResourceSpec(
                key: 'sppg', module: 'sppg', label: 'data SPPG', model: Sppg::class, actions: ['R', 'U'],
                search: ['nama', 'kode'],
                list: ['id', 'kode', 'nama', 'alamat', 'kapasitas_porsi_harian', 'status_operasional'],
                detail: ['mitra_yayasan'],
                filters: ['status_operasional' => ['type' => 'string', 'enum' => self::values(StatusOperasional::class)]],
                order: [['nama', 'asc']],
                scopeColumn: 'id',
                update: UpdateSppgRequest::class, routeParameter: 'sppg',
                exclude: ['telepon'],
            ),

            // 4. Data Titik Layanan: cari_/buat_ ditulis sebagai kelas sendiri (Tools/TitikLayanan).
            new ResourceSpec(
                key: 'titik_layanan', module: 'titik-layanan', label: 'titik layanan', model: TitikLayanan::class,
                search: ['nama', 'alamat', 'pic_nama'],
                list: ['id', 'nama', 'jenis', 'alamat', 'jarak_km', 'jumlah_sasaran', 'pic_nama', 'is_active'],
                filters: ['jenis' => ['type' => 'string', 'enum' => self::values(JenisTitikLayanan::class)]],
                order: [['nama', 'asc'], ['id', 'asc']],
                store: StoreTitikLayananRequest::class, update: UpdateTitikLayananRequest::class, routeParameter: 'titikLayanan',
                skip: ['cari_titik_layanan', 'buat_titik_layanan'],
                exclude: ['pic_telepon'],
                initials: ['pic_nama'],
            ),

            // 5. Data Penerima Manfaat (data anak, UU PDP): ke LLM hanya inisial + ID, tanpa sekolah/kelas;
            //    catatan alergi (data kesehatan) tidak disajikan/ditawarkan.
            new ResourceSpec(
                key: 'penerima_manfaat', module: 'penerima-manfaat', label: 'penerima manfaat', model: PenerimaManfaat::class,
                search: ['nama'],
                list: ['id', 'nama', 'kelompok_sasaran', 'is_active'],
                filters: ['kelompok_sasaran' => ['type' => 'string', 'enum' => self::values(KelompokSasaran::class)]],
                order: [['nama', 'asc'], ['id', 'asc']],
                store: StorePenerimaManfaatRequest::class, update: UpdatePenerimaManfaatRequest::class, routeParameter: 'penerimaManfaat',
                exclude: ['catatan_alergi'],
                initials: ['nama'],
            ),

            // 6. Data Bahan Baku (+ mutasi stok lewat CatatMutasiStokTool).
            new ResourceSpec(
                key: 'bahan_baku', module: 'bahan-baku', label: 'bahan baku', model: BahanBaku::class,
                search: ['nama', 'kode'],
                list: ['id', 'kode', 'nama', 'kategori', 'satuan', 'stok', 'stok_minimum', 'kedaluwarsa_terdekat', 'pemasok' => 'pemasok.nama'],
                filters: [
                    'kategori' => ['type' => 'string', 'enum' => self::values(KategoriBahan::class)],
                    'menipis' => ['type' => 'boolean', 'scope' => 'stokMenipis', 'description' => 'Hanya stok di bawah/sama dengan minimum.'],
                    'akan_kedaluwarsa' => ['type' => 'boolean', 'scope' => 'akanKedaluwarsa', 'description' => 'Hanya yang kedaluwarsa dalam 3 hari.'],
                ],
                order: [['nama', 'asc'], ['id', 'asc']],
                store: StoreBahanBakuRequest::class, update: UpdateBahanBakuRequest::class, routeParameter: 'bahan_baku',
            ),

            // 7. Data Pemasok: kontak tidak disajikan/ditawarkan.
            new ResourceSpec(
                key: 'pemasok', module: 'pemasok', label: 'pemasok', model: Pemasok::class,
                search: ['nama', 'jenis_bahan'],
                list: ['id', 'nama', 'jenis', 'jenis_bahan', 'alamat', 'is_active'],
                filters: ['jenis' => ['type' => 'string', 'enum' => self::values(JenisPemasok::class)]],
                order: [['nama', 'asc'], ['id', 'asc']],
                store: StorePemasokRequest::class, update: UpdatePemasokRequest::class, routeParameter: 'pemasok',
                exclude: ['kontak'],
            ),

            // 8. Data Menu dan Gizi: menu + standar gizi (penanda 'standar_gizi' pada draf).
            new ResourceSpec(
                key: 'menu', module: 'menu-gizi', label: 'menu', model: Menu::class,
                search: ['nama_menu', 'siklus'],
                list: ['id', 'nama_menu', 'kelompok_sasaran', 'tanggal_sajian', 'siklus', 'energi_kkal', 'protein_g', 'lemak_g', 'karbohidrat_g', 'serat_g'],
                detail: ['catatan'],
                filters: ['kelompok_sasaran' => ['type' => 'string', 'enum' => self::values(KelompokSasaran::class)]],
                date: 'tanggal_sajian', order: [['tanggal_sajian', 'desc'], ['id', 'desc']],
                store: StoreMenuRequest::class, update: UpdateMenuRequest::class, routeParameter: 'menu',
            ),
            new ResourceSpec(
                key: 'standar_gizi', module: 'menu-gizi', label: 'standar gizi', model: StandarGizi::class,
                list: ['id', 'kelompok_sasaran', 'energi_min', 'energi_max', 'protein_min', 'protein_max', 'lemak_min', 'lemak_max', 'karbohidrat_min', 'karbohidrat_max', 'serat_min', 'serat_max'],
                filters: ['kelompok_sasaran' => ['type' => 'string', 'enum' => self::values(KelompokSasaran::class)]],
                store: StoreStandarGiziRequest::class, update: StoreStandarGiziRequest::class, routeParameter: 'standar_gizi',
                marker: 'standar_gizi',
            ),

            // 9. Data Jadwal Produksi dan Distribusi (sub-jenis mengikuti unit relawan).
            new ResourceSpec(
                key: 'jadwal_produksi', module: 'jadwal', label: 'jadwal produksi', model: JadwalProduksi::class,
                search: ['menu.nama_menu'],
                list: ['id', 'tanggal_produksi', 'jam_mulai_masak', 'menu' => 'menu.nama_menu', 'target_porsi', 'status'],
                detail: ['catatan'],
                filters: ['status' => ['type' => 'string', 'enum' => self::values(StatusJadwalProduksi::class)]],
                date: 'tanggal_produksi', order: [['tanggal_produksi', 'desc'], ['id', 'desc']],
                store: StoreJadwalProduksiRequest::class, update: UpdateJadwalProduksiRequest::class, routeParameter: 'jadwal',
                jenis: 'produksi',
            ),
            new ResourceSpec(
                key: 'jadwal_distribusi', module: 'jadwal', label: 'jadwal distribusi', model: JadwalDistribusi::class,
                search: ['titikLayanan.nama'],
                list: ['id', 'tanggal_distribusi', 'jam_berangkat', 'jam_rencana_tiba', 'titik_layanan' => 'titikLayanan.nama', 'armada' => 'armada.plat_nomor', 'pengemudi' => 'pengemudi.nama', 'jumlah_porsi', 'status'],
                detail: ['catatan'],
                filters: ['status' => ['type' => 'string', 'enum' => self::values(StatusJadwalDistribusi::class)]],
                date: 'tanggal_distribusi', order: [['tanggal_distribusi', 'desc'], ['id', 'desc']],
                store: StoreJadwalDistribusiRequest::class, update: UpdateJadwalDistribusiRequest::class, routeParameter: 'jadwal_distribusi',
                marker: 'distribusi', jenis: 'distribusi',
                initials: ['pengemudi'],
            ),

            // 10. Data Log Produksi Harian.
            new ResourceSpec(
                key: 'log_produksi', module: 'log-produksi', label: 'log produksi harian', model: LogProduksi::class,
                list: ['id', 'jadwal_produksi_id', 'tanggal_produksi' => 'jadwalProduksi.tanggal_produksi', 'realisasi_porsi', 'sisa_makanan_kg', 'jam_selesai', 'kode_batch'],
                detail: ['kendala'],
                date: 'created_at',
                store: StoreLogProduksiRequest::class, update: UpdateLogProduksiRequest::class, routeParameter: 'log_produksi',
            ),

            // 11. Data Pemeriksaan Mutu dan Keamanan Pangan.
            new ResourceSpec(
                key: 'pemeriksaan_mutu', module: 'mutu', label: 'pemeriksaan mutu pangan', model: PemeriksaanMutu::class,
                list: ['id', 'jadwal_produksi_id', 'tanggal_produksi' => 'jadwalProduksi.tanggal_produksi', 'waktu_pemeriksaan', 'suhu_makanan_c', 'kebersihan_dapur', 'kebersihan_petugas', 'rasa_baik', 'aroma_baik', 'tampilan_baik', 'sampel_disimpan', 'hasil'],
                detail: ['tindakan_korektif', 'catatan_organoleptik'],
                filters: ['hasil' => ['type' => 'string', 'enum' => self::values(HasilMutu::class)]],
                date: 'waktu_pemeriksaan',
                store: StorePemeriksaanMutuRequest::class, update: UpdatePemeriksaanMutuRequest::class, routeParameter: 'pemeriksaan_mutu',
            ),

            // 12. Data Armada dan Pengemudi: nomor SIM dan HP pengemudi tidak disajikan/ditawarkan.
            new ResourceSpec(
                key: 'armada', module: 'armada', label: 'armada', model: Armada::class,
                search: ['plat_nomor', 'jenis'],
                list: ['id', 'plat_nomor', 'jenis', 'kapasitas_box', 'status'],
                filters: ['status' => ['type' => 'string', 'enum' => self::values(StatusArmada::class)]],
                order: [['plat_nomor', 'asc']],
                store: StoreArmadaRequest::class, update: UpdateArmadaRequest::class, routeParameter: 'armada',
            ),
            new ResourceSpec(
                key: 'pengemudi', module: 'armada', label: 'pengemudi', model: Pengemudi::class,
                search: ['nama'],
                list: ['id', 'nama', 'is_active', 'sim_berlaku_hingga'],
                order: [['nama', 'asc']],
                store: StorePengemudiRequest::class, update: UpdatePengemudiRequest::class, routeParameter: 'pengemudi',
                marker: 'pengemudi', exclude: ['no_sim', 'no_hp'],
                initials: ['nama'],
            ),

            // 13. Data Bukti Distribusi: foto/berita acara/koordinat diunggah di Mini App, tidak lewat AI.
            new ResourceSpec(
                key: 'bukti_distribusi', module: 'bukti-distribusi', label: 'bukti distribusi', model: BuktiDistribusi::class,
                list: ['id', 'jadwal_distribusi_id', 'tanggal_distribusi' => 'jadwalDistribusi.tanggal_distribusi', 'titik_layanan' => 'jadwalDistribusi.titikLayanan.nama', 'waktu_berangkat', 'waktu_tiba', 'porsi_diterima', 'terlambat', 'menit_keterlambatan'],
                detail: ['catatan_selisih', 'catatan'],
                date: 'created_at',
                store: StoreBuktiDistribusiRequest::class, update: UpdateBuktiDistribusiRequest::class, routeParameter: 'buktiDistribusi',
                exclude: ['latitude', 'longitude'],
            ),

            // 14. Data Keuangan: pencairan dana + pengeluaran (nominal rupiah bulat).
            new ResourceSpec(
                key: 'pencairan_dana', module: 'keuangan', label: 'pencairan dana', model: PencairanDana::class,
                search: ['periode', 'sumber_dana', 'no_referensi'],
                list: ['id', 'periode', 'sumber_dana', 'nominal', 'tanggal_cair', 'no_referensi'],
                detail: ['catatan'],
                date: 'tanggal_cair', order: [['tanggal_cair', 'desc'], ['id', 'desc']], rupiah: ['nominal'],
                store: StorePencairanRequest::class, update: UpdatePencairanRequest::class, routeParameter: 'pencairanDana',
            ),
            new ResourceSpec(
                key: 'pengeluaran', module: 'keuangan', label: 'pengeluaran', model: Pengeluaran::class,
                search: ['uraian'],
                list: ['id', 'tanggal', 'kategori', 'uraian', 'nominal', 'melebihi_anggaran', 'pemasok' => 'pemasok.nama'],
                filters: ['kategori' => ['type' => 'string', 'enum' => self::values(KategoriPengeluaran::class)]],
                date: 'tanggal', order: [['tanggal', 'desc'], ['id', 'desc']], rupiah: ['nominal'],
                store: StorePengeluaranRequest::class, update: UpdatePengeluaranRequest::class, routeParameter: 'pengeluaran',
                marker: 'pengeluaran',
            ),

            // 15. Data Feedback dan Keluhan (deskripsi ditulis pengguna lain: DATA tak tepercaya).
            new ResourceSpec(
                key: 'keluhan', module: 'keluhan', label: 'feedback dan keluhan', model: FeedbackKeluhan::class,
                search: ['deskripsi', 'titikLayanan.nama'],
                list: ['id', 'sumber', 'kategori', 'prioritas', 'status', 'titik_layanan' => 'titikLayanan.nama', 'deskripsi', 'created_at'],
                detail: ['tindak_lanjut'],
                filters: [
                    'status' => ['type' => 'string', 'enum' => self::values(StatusKeluhan::class)],
                    'prioritas' => ['type' => 'string', 'enum' => self::values(PrioritasKeluhan::class)],
                    'kategori' => ['type' => 'string', 'enum' => self::values(KategoriKeluhan::class)],
                    'kritis' => ['type' => 'boolean', 'scope' => 'kritis', 'description' => 'Hanya keluhan prioritas kritis.'],
                    'terbuka' => ['type' => 'boolean', 'scope' => 'terbuka', 'description' => 'Hanya keluhan yang belum selesai.'],
                ],
                date: 'created_at',
                store: StoreKeluhanRequest::class, update: UpdateKeluhanRequest::class, routeParameter: 'keluhan',
            ),

            // 16. Laporan Rekapitulasi Harian Operasional (ringkasan dibaca lewat detail; tanpa hapus).
            new ResourceSpec(
                key: 'rekap_harian', module: 'rekap-harian', label: 'rekap harian operasional', model: RekapHarian::class,
                actions: ['R', 'C', 'U'],
                list: ['id', 'tanggal', 'status_kirim', 'dikirim_pada'],
                detail: ['ringkasan', 'catatan'],
                filters: ['status_kirim' => ['type' => 'string', 'enum' => self::values(StatusKirim::class)]],
                date: 'tanggal', order: [['tanggal', 'desc'], ['id', 'desc']],
                store: StoreRekapHarianRequest::class, update: UpdateRekapHarianRequest::class, routeParameter: 'rekapHarian',
            ),
        ];
    }

    /** Spesifikasi `catat_mutasi_stok` (draf mutasi masuk/keluar/penyesuaian pada bahan baku). */
    public static function mutasiStok(): ResourceSpec
    {
        return new ResourceSpec(
            key: 'mutasi_stok', module: 'bahan-baku', label: 'mutasi stok bahan baku', model: BahanBaku::class, actions: ['U'],
            update: StoreMutasiStokRequest::class, routeParameter: 'bahan_baku',
            marker: 'mutasi_stok',
            hints: ['jumlah' => 'Jumlah dalam satuan bahan baku; penyesuaian boleh bertanda negatif.'],
        );
    }

    /**
     * @param  class-string<BackedEnum>  $enum
     * @return list<string|int>
     */
    private static function values(string $enum): array
    {
        return array_map(fn (BackedEnum $case) => $case->value, $enum::cases());
    }
}
