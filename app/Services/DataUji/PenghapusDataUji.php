<?php

namespace App\Services\DataUji;

use App\Models\Sppg;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Menghapus HANYA data turunan SPPG uji (lihat PenandaDataUji), urutan anak ke induk, dalam satu transaksi.
 *
 * Penghapusan memakai query builder (force delete; tanpa event model) agar tidak membuat baris audit baru dan
 * tidak tertahan penjaga model append-only (HargaPemasok, PengajuanMenu, PersetujuanPengeluaran). Setiap
 * query dibatasi `sppg_id` SPPG uji (atau `menu_id` milik SPPG uji untuk menu_bahan); tidak ada penghapusan
 * tanpa kondisi.
 *
 * Tidak dihapus: audit_logs (append-only; FK nullOnDelete sehingga jejak tetap ada tanpa tautan SPPG) dan
 * ai_interactions (FK nullOnDelete). Akun pengguna tidak pernah dihapus: bila masih ada pengguna/pivot investor
 * yang tertaut ke SPPG uji, penghapusan ditolak kecuali opsi $lepasPengguna (hanya mengosongkan users.sppg_id dan
 * menghapus pivot investor_sppg untuk SPPG uji; akun tetap ada). Sesi akun yang dilepas dicabut dan satu-satunya
 * baris audit yang dibuat adalah auth.sesi_dicabut_otomatis (pemicu sppg_uji_dihapus) per akun yang punya token.
 */
final class PenghapusDataUji
{
    /** Tabel ber-kolom sppg_id, urutan anak -> induk (menu_bahan disisipkan khusus). */
    private const URUTAN = [
        'notifikasi_perjalanans',
        'bukti_distribusis',
        'log_produksis',
        'pemeriksaan_mutus',
        'feedback_keluhans',
        'jadwal_distribusis',
        'mutasi_stoks',
        'persetujuan_pengeluarans',
        'pengeluarans',
        'pembelian_bahans',
        'pencairan_danas',
        'peringatan_anggarans',
        'rekap_harians',
        'jadwal_produksis',
        'pengajuan_menus',
        'menu_bahan',
        'menus',
        'harga_pemasoks',
        'bahan_bakus',
        'pemasoks',
        'standar_gizis',
        'penerima_manfaats',
        'titik_layanans',
        'armadas',
        'pengemudis',
        'lampirans',
        'ai_drafts',
        'invitations',
        'bot_aksi',
        'permintaan_reset_passwords',
    ];

    /**
     * Hitung (tanpa menulis) baris yang akan dihapus dan penghalang penghapusan.
     *
     * @return array{baris: array<string, int>, pengguna: int, investor: int}
     */
    public function rencana(Sppg $sppg): array
    {
        $this->pastikanDataUji($sppg);

        $baris = [];

        foreach (self::URUTAN as $tabel) {
            $baris[$tabel] = $this->kueri($tabel, (int) $sppg->getKey())->count();
        }

        $baris['sppgs'] = 1;

        return [
            'baris' => $baris,
            'pengguna' => DB::table('users')->where('sppg_id', $sppg->getKey())->count(),
            'investor' => DB::table('investor_sppg')->where('sppg_id', $sppg->getKey())->count(),
        ];
    }

    /**
     * @return array<string, int> jumlah baris terhapus per tabel
     *
     * @throws RuntimeException bila masih ada pengguna tertaut dan $lepasPengguna false
     */
    public function hapus(Sppg $sppg, bool $lepasPengguna): array
    {
        $rencana = $this->rencana($sppg);
        $id = (int) $sppg->getKey();

        if (($rencana['pengguna'] > 0 || $rencana['investor'] > 0) && ! $lepasPengguna) {
            throw new RuntimeException(sprintf(
                'Masih ada %d pengguna dan %d tautan investor yang terhubung ke SPPG uji. Akun tidak akan dihapus otomatis; '
                .'pindahkan akun ke SPPG lain lewat Data Pengguna, atau jalankan ulang dengan --lepas-pengguna.',
                $rencana['pengguna'], $rencana['investor'],
            ));
        }

        $berkas = DB::table('lampirans')->where('sppg_id', $id)->pluck('path')->filter()->all();
        $terhapus = [];

        DB::transaction(function () use ($id, $lepasPengguna, &$terhapus): void {
            if ($lepasPengguna) {
                DB::table('investor_sppg')->where('sppg_id', $id)->delete();
                // sppg_id berubah lewat query builder (tanpa event User::updated): cabut sesi secara eksplisit
                // agar token lama tidak tetap berlaku tanpa SPPG (pencabutan seketika, R-03).
                User::query()->withoutGlobalScopes()->withTrashed()->where('sppg_id', $id)->get()
                    ->each(function (User $u): void {
                        $jumlah = $u->cabutSemuaSesi();

                        if ($jumlah > 0) {
                            $u->catatPencabutanOtomatis('sppg_uji_dihapus', $jumlah, ['kolom' => ['sppg_id']]);
                        }
                    });
                DB::table('users')->where('sppg_id', $id)->update(['sppg_id' => null]);
            }

            // Rujukan melingkar pengeluaran <-> pembelian_bahan diputus lebih dulu.
            DB::table('pembelian_bahans')->where('sppg_id', $id)->update(['pengeluaran_id' => null]);
            DB::table('pengeluarans')->where('sppg_id', $id)->update(['pembelian_bahan_id' => null]);

            foreach (self::URUTAN as $tabel) {
                $terhapus[$tabel] = $this->kueri($tabel, $id)->delete();
            }

            $terhapus['sppgs'] = DB::table('sppgs')->where('id', $id)->where('kode', PenandaDataUji::KODE_SPPG)->delete();
        });

        // Berkas dihapus setelah transaksi berhasil (penghapusan berkas tidak dapat di-rollback).
        if ($berkas !== []) {
            Storage::disk((string) config('unggahan.disk', 'uploads'))->delete($berkas);
        }

        $terhapus['berkas_lampiran'] = count($berkas);

        return $terhapus;
    }

    private function pastikanDataUji(Sppg $sppg): void
    {
        if (! PenandaDataUji::adalahDataUji($sppg)) {
            throw new RuntimeException('SPPG ini bukan data uji (kode atau nama tidak sesuai penanda); tidak ada yang dihapus.');
        }
    }

    private function kueri(string $tabel, int $sppgId): Builder
    {
        if ($tabel === 'menu_bahan') {
            return DB::table('menu_bahan')->whereIn('menu_id', DB::table('menus')->where('sppg_id', $sppgId)->select('id'));
        }

        return DB::table($tabel)->where('sppg_id', $sppgId);
    }
}
