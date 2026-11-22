<?php

namespace App\Support;

use App\Models\AiDraft;
use App\Models\Armada;
use App\Models\BahanBaku;
use App\Models\BuktiDistribusi;
use App\Models\FeedbackKeluhan;
use App\Models\HargaPemasok;
use App\Models\Invitation;
use App\Models\JadwalDistribusi;
use App\Models\JadwalProduksi;
use App\Models\LaporanBgn;
use App\Models\LogProduksi;
use App\Models\Menu;
use App\Models\NotifikasiPerjalanan;
use App\Models\Pemasok;
use App\Models\PembelianBahan;
use App\Models\PemeriksaanMutu;
use App\Models\PencairanDana;
use App\Models\PenerimaManfaat;
use App\Models\PengajuanMenu;
use App\Models\Pengeluaran;
use App\Models\Pengemudi;
use App\Models\PersetujuanPengeluaran;
use App\Models\Sppg;
use App\Models\StandarGizi;
use App\Models\TitikLayanan;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Pemetaan jenis model (kolom audit_logs.auditable_type) ke nama modul yang ramah (`keluhan`, `bahan-baku`)
 * untuk GET /api/v1/audit-log, serta penyaring nilai audit saat ditampilkan (defense in depth).
 *
 * Penyaring tampilan TIDAK mengubah cara audit dicatat: ia menyaring ulang nilai_lama/nilai_baru memakai
 * daftar kolom terlarang model (Auditable::auditKolomTerlarang) dan pola kunci sensitif, sehingga log lama
 * yang tercatat sebelum sebuah kolom dikecualikan tetap tidak bocor.
 */
final class ModulAudit
{
    /**
     * Modul yang dikenal: slug => [kelas model, label].
     *
     * @var array<string, array{0: class-string<Model>, 1: string}>
     */
    public const MODUL = [
        'pengguna' => [User::class, 'Pengguna'],
        'sppg' => [Sppg::class, 'SPPG'],
        'titik-layanan' => [TitikLayanan::class, 'Titik Layanan'],
        'penerima-manfaat' => [PenerimaManfaat::class, 'Penerima Manfaat'],
        'bahan-baku' => [BahanBaku::class, 'Bahan Baku'],
        'pemasok' => [Pemasok::class, 'Pemasok'],
        'harga-pemasok' => [HargaPemasok::class, 'Harga Pemasok'],
        'menu' => [Menu::class, 'Menu'],
        'standar-gizi' => [StandarGizi::class, 'Standar Gizi'],
        'pengajuan-menu' => [PengajuanMenu::class, 'Pengajuan Menu'],
        'jadwal-produksi' => [JadwalProduksi::class, 'Jadwal Produksi'],
        'jadwal-distribusi' => [JadwalDistribusi::class, 'Jadwal Distribusi'],
        'log-produksi' => [LogProduksi::class, 'Log Produksi Harian'],
        'pemeriksaan-mutu' => [PemeriksaanMutu::class, 'Pemeriksaan Mutu'],
        'armada' => [Armada::class, 'Armada'],
        'pengemudi' => [Pengemudi::class, 'Pengemudi'],
        'bukti-distribusi' => [BuktiDistribusi::class, 'Bukti Distribusi'],
        'pencairan-dana' => [PencairanDana::class, 'Pencairan Dana'],
        'pengeluaran' => [Pengeluaran::class, 'Pengeluaran'],
        'persetujuan-pengeluaran' => [PersetujuanPengeluaran::class, 'Persetujuan Pengeluaran'],
        'pembelian-bahan' => [PembelianBahan::class, 'Pembelian Bahan'],
        'keluhan' => [FeedbackKeluhan::class, 'Feedback dan Keluhan'],
        'notifikasi-perjalanan' => [NotifikasiPerjalanan::class, 'Notifikasi Perjalanan'],
        'laporan-bgn' => [LaporanBgn::class, 'Laporan BGN'],
        'undangan' => [Invitation::class, 'Undangan Pengguna'],
        'draf-ai' => [AiDraft::class, 'Draf Agen AI'],
    ];

    /** Aksi bawaan trait Auditable; peristiwa khusus (auth.*, ai_eksekusi_draf, dst.) ikut dari data. */
    public const AKSI_BAWAAN = ['created', 'updated', 'deleted', 'restored'];

    /** Pola kunci yang tidak pernah ditampilkan, apa pun model dan isi log lamanya. */
    private const POLA_TERLARANG = '/password|passwd|sandi|remember|secret|api_?key|init_?data|token|hash|(^|_)nik($|_)|(^|_)ktp($|_)|telegram_id|chat_id/i';

    /** @var array<string, list<string>> */
    private static array $cacheKolom = [];

    /** Slug modul untuk sebuah auditable_type (nama kelas lengkap). Jenis tak dikenal -> kebab dari nama kelas. */
    public static function slug(string $type): string
    {
        foreach (self::MODUL as $slug => [$kelas]) {
            if ($kelas === $type) {
                return $slug;
            }
        }

        return Str::kebab(class_basename($type));
    }

    public static function label(string $type): string
    {
        $slug = self::slug($type);

        return self::MODUL[$slug][1] ?? Str::headline($slug);
    }

    /** @return class-string<Model>|null null bila slug bukan modul yang dikenal. */
    public static function kelas(string $slug): ?string
    {
        return self::MODUL[$slug][0] ?? null;
    }

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_keys(self::MODUL);
    }

    /**
     * Saring nilai audit untuk tampilan. Kunci sensitif dibuang rekursif.
     *
     * @param  array<string, mixed>|null  $nilai
     * @return array<string, mixed>|null
     */
    public static function saring(?array $nilai, string $type): ?array
    {
        if ($nilai === null) {
            return null;
        }

        return self::bersihkan($nilai, array_flip(self::kolomTerlarang($type)));
    }

    /**
     * @param  array<mixed>  $nilai
     * @param  array<string, int>  $terlarang
     * @return array<mixed>
     */
    private static function bersihkan(array $nilai, array $terlarang): array
    {
        $hasil = [];

        foreach ($nilai as $kunci => $isi) {
            if (is_string($kunci) && (isset($terlarang[$kunci]) || preg_match(self::POLA_TERLARANG, $kunci) === 1)) {
                continue;
            }

            $hasil[$kunci] = is_array($isi) ? self::bersihkan($isi, $terlarang) : $isi;
        }

        return $hasil;
    }

    /** @return list<string> */
    private static function kolomTerlarang(string $type): array
    {
        if (isset(self::$cacheKolom[$type])) {
            return self::$cacheKolom[$type];
        }

        $kolom = User::AUDIT_NEVER_LOG;

        if (is_a($type, Model::class, true)) {
            /** @var Model $model */
            $model = new $type;
            $kolom = array_merge($kolom, $model->getHidden());

            if (method_exists($model, 'auditKolomTerlarang')) {
                $kolom = array_merge($kolom, $model->auditKolomTerlarang());
            }
        }

        return self::$cacheKolom[$type] = array_values(array_unique($kolom));
    }
}
