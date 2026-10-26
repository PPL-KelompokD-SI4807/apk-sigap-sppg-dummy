<?php

namespace App\Services\Persetujuan;

use App\Enums\StatusPengajuanMenu;
use App\Enums\StatusPersetujuanMenu;
use App\Events\MenuDiajukan;
use App\Events\MenuDiputuskan;
use App\Models\Menu;
use App\Models\PengajuanMenu;
use App\Models\Scopes\SppgScope;
use App\Models\User;
use App\Services\Auth\GalatAuth;
use App\Support\SppgAktif;
use Illuminate\Support\Facades\DB;

/**
 * Alur persetujuan menu oleh investor (R-06b-1).
 *
 * Urutan kunci baris SELALU menu -> pengajuan (sama dengan jalur ubah menu) agar tidak deadlock.
 * Semua angka snapshot dihitung server (BiayaMenuService); klien hanya mengirim versi + catatan.
 */
class PersetujuanMenuService
{
    public const ALASAN_STATUS_MENU = 'status_menu_tidak_valid';

    public const ALASAN_VERSI = 'versi_menu_tidak_cocok';

    public const ALASAN_SUDAH_DIPUTUSKAN = 'pengajuan_sudah_diputuskan';

    public const ALASAN_MENU_BERUBAH = 'menu_berubah_setelah_diajukan';

    public const ALASAN_HARGA = 'harga_bahan_belum_lengkap';

    public const ALASAN_PORSI_NOL = 'jumlah_porsi_nol';

    public const ALASAN_BIAYA_MELEBIHI_BATAS = 'biaya_melebihi_batas';

    public const CATATAN_MENU_DIUBAH = 'Menu diubah setelah diajukan; pengajuan dibatalkan otomatis.';

    public const CATATAN_MENU_DIHAPUS = 'Menu dihapus setelah diajukan; pengajuan dibatalkan otomatis.';

    public function __construct(private readonly BiayaMenuService $biaya) {}

    /**
     * Ajukan menu: kunci menu, cocokkan versi, bekukan snapshot, status menu -> diajukan.
     */
    public function ajukan(User $pengaju, Menu $menu, int $versi, ?string $catatan): PengajuanMenu
    {
        $pengajuan = DB::transaction(function () use ($pengaju, $menu, $versi, $catatan): PengajuanMenu {
            $kunci = $this->kunciMenu((int) $menu->getKey());

            if ((int) $kunci->sppg_id !== (int) $pengaju->sppg_id) {
                abort(404);
            }

            if (! $kunci->status_persetujuan->bisaDiajukan()) {
                throw GalatAuth::respons(409, self::ALASAN_STATUS_MENU, 'Menu tidak dapat diajukan pada status saat ini.', tambahan: ['status_persetujuan' => $kunci->status_persetujuan->value]);
            }

            if ((int) $kunci->versi !== $versi) {
                throw GalatAuth::respons(409, self::ALASAN_VERSI, 'Menu sudah berubah. Muat ulang menu lalu ajukan kembali.', tambahan: ['versi_server' => (int) $kunci->versi]);
            }

            $hitung = $this->biaya->hitung($kunci);

            if (! $hitung['lengkap']) {
                throw GalatAuth::respons(
                    422,
                    self::ALASAN_HARGA,
                    $hitung['bahan'] === [] ? 'Menu belum memiliki komposisi bahan.' : 'Harga pemasok atau konversi satuan belum lengkap untuk semua bahan.',
                    errors: ['bahan' => $hitung['bahan'] === []
                        ? ['Menu belum memiliki komposisi bahan.']
                        : array_map(fn (array $b) => sprintf('%s: %s', $b['nama'], $b['masalah'] === BiayaMenuService::MASALAH_TANPA_HARGA ? 'belum ada harga pemasok yang berlaku' : 'konversi satuan ke gram belum diketahui'), $hitung['bahan_bermasalah'])],
                    tambahan: ['bahan_bermasalah' => $hitung['bahan_bermasalah']],
                );
            }

            if ((int) $hitung['jumlah_porsi'] === 0) {
                throw GalatAuth::respons(
                    422,
                    self::ALASAN_PORSI_NOL,
                    'Jumlah porsi 0: belum ada jadwal produksi maupun penerima manfaat aktif sebagai dasar perhitungan.',
                    errors: ['jumlah_porsi' => ['Jumlah porsi 0. Tambahkan penerima manfaat aktif atau jadwal produksi terlebih dahulu.']],
                );
            }

            if (BiayaMenuService::melebihiBatas($hitung)) {
                throw GalatAuth::respons(
                    422,
                    self::ALASAN_BIAYA_MELEBIHI_BATAS,
                    'Biaya hasil perhitungan melebihi batas yang dapat disimpan. Periksa harga pemasok, takaran, dan konversi satuan.',
                    errors: ['bahan' => ['Biaya per porsi atau total melebihi batas. Periksa harga pemasok, takaran, dan konversi satuan.']],
                );
            }

            $kunci->loadMissing('bahanBakus');
            $nomor = (int) PengajuanMenu::withoutGlobalScope(SppgScope::class)->where('menu_id', $kunci->id)->max('nomor') + 1;

            $pengajuan = new PengajuanMenu;
            $pengajuan->forceFill([
                'sppg_id' => $kunci->sppg_id,
                'menu_id' => $kunci->id,
                'nomor' => $nomor,
                'menu_versi' => $kunci->versi,
                'status' => StatusPengajuanMenu::Diajukan,
                'rincian' => [
                    'menu' => [
                        'nama_menu' => $kunci->nama_menu,
                        'kelompok_sasaran' => $kunci->kelompok_sasaran->value,
                        'gizi_per_porsi' => [
                            'energi_kkal' => (float) $kunci->energi_kkal,
                            'protein_g' => (float) $kunci->protein_g,
                            'lemak_g' => (float) $kunci->lemak_g,
                            'karbohidrat_g' => (float) $kunci->karbohidrat_g,
                            'serat_g' => (float) $kunci->serat_g,
                        ],
                    ],
                    'bahan' => $hitung['bahan'],
                    'tanggal_acuan' => $hitung['tanggal_acuan'],
                ],
                'biaya_per_porsi' => $hitung['biaya_per_porsi'],
                'jumlah_porsi' => $hitung['jumlah_porsi'],
                'sumber_porsi' => $hitung['sumber_porsi'],
                'keterangan_asumsi' => $hitung['keterangan_asumsi'],
                'total_biaya' => $hitung['total_biaya'],
                'catatan_pengaju' => $catatan,
                'diajukan_oleh' => $pengaju->getKey(),
                'diajukan_pada' => now(),
            ])->save();

            $kunci->forceFill(['status_persetujuan' => StatusPersetujuanMenu::Diajukan])->save();

            event(MenuDiajukan::dari($pengajuan, $kunci->nama_menu));

            return $pengajuan;
        });

        return $pengajuan;
    }

    /**
     * Keputusan investor. Gagal -> 409 terstruktur (alasan) tanpa mengubah data apa pun.
     */
    public function putuskan(User $investor, PengajuanMenu $pengajuan, string $keputusan, ?string $catatan, int $versiMenu): PengajuanMenu
    {
        return DB::transaction(function () use ($investor, $pengajuan, $keputusan, $catatan, $versiMenu): PengajuanMenu {
            $menu = Menu::withoutGlobalScope(SppgScope::class)->withTrashed()->whereKey($pengajuan->menu_id)->lockForUpdate()->first();
            $kunci = PengajuanMenu::withoutGlobalScope(SppgScope::class)->whereKey($pengajuan->getKey())->lockForUpdate()->firstOrFail();

            // Lapis kedua selain Policy: investor wajib pemilik SPPG pengajuan (pivot investor_sppg).
            if (! in_array((int) $kunci->sppg_id, app(SppgAktif::class)->milik($investor), true)) {
                abort(403);
            }

            if ($kunci->status !== StatusPengajuanMenu::Diajukan) {
                throw GalatAuth::respons(409, self::ALASAN_SUDAH_DIPUTUSKAN, 'Pengajuan ini sudah diputuskan atau dibatalkan.', tambahan: ['status' => $kunci->status->value]);
            }

            if ($menu === null
                || $menu->trashed()
                || (int) $menu->sppg_id !== (int) $kunci->sppg_id
                || $menu->status_persetujuan !== StatusPersetujuanMenu::Diajukan
                || (int) $menu->versi !== (int) $kunci->menu_versi) {
                throw GalatAuth::respons(409, self::ALASAN_MENU_BERUBAH, 'Menu telah berubah setelah diajukan. Pengajuan tidak dapat diputuskan.');
            }

            if ((int) $menu->versi !== $versiMenu) {
                throw GalatAuth::respons(409, self::ALASAN_VERSI, 'Versi menu yang Anda lihat sudah tidak berlaku. Muat ulang pengajuan.', tambahan: ['versi_server' => (int) $menu->versi]);
            }

            $kunci->forceFill([
                'status' => StatusPengajuanMenu::from($keputusan),
                'diputuskan_oleh' => $investor->getKey(),
                'diputuskan_pada' => now(),
                'catatan_keputusan' => $catatan,
            ])->save();

            $menu->forceFill(['status_persetujuan' => StatusPersetujuanMenu::from($keputusan)])->save();

            event(MenuDiputuskan::dari($kunci));

            return $kunci;
        });
    }

    /**
     * Awal perubahan menu (atribut/komposisi): kunci menu, naikkan versi, kembali ke draf, batalkan
     * pengajuan yang masih menunggu. Dipanggil di DALAM transaksi pemanggil; $menu disegarkan dari
     * baris terkunci sebelum diisi data baru.
     */
    public function mulaiUbah(Menu $menu): void
    {
        $this->kunciMenu((int) $menu->getKey());
        $menu->refresh();

        $this->batalkanMenunggu($menu, self::CATATAN_MENU_DIUBAH);
        $menu->forceFill([
            'versi' => (int) $menu->versi + 1,
            'status_persetujuan' => StatusPersetujuanMenu::Draf,
        ])->save();
    }

    /** Awal penghapusan menu: kunci menu lalu batalkan pengajuan yang masih menunggu. */
    public function mulaiHapus(Menu $menu): void
    {
        $this->kunciMenu((int) $menu->getKey());
        $menu->refresh();
        $this->batalkanMenunggu($menu, self::CATATAN_MENU_DIHAPUS);
    }

    private function batalkanMenunggu(Menu $menu, string $catatan): void
    {
        $menunggu = PengajuanMenu::withoutGlobalScope(SppgScope::class)
            ->where('menu_id', $menu->getKey())
            ->where('status', StatusPengajuanMenu::Diajukan->value)
            ->orderBy('id')->lockForUpdate()->get();

        foreach ($menunggu as $pengajuan) {
            $pengajuan->forceFill([
                'status' => StatusPengajuanMenu::Dibatalkan,
                'diputuskan_oleh' => null,
                'diputuskan_pada' => now(),
                'catatan_keputusan' => $catatan,
            ])->save();
        }
    }

    private function kunciMenu(int $id): Menu
    {
        return Menu::withoutGlobalScope(SppgScope::class)->whereKey($id)->lockForUpdate()->firstOrFail();
    }
}
