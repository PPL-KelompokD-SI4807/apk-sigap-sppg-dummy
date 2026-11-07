<?php

namespace App\Policies;

use App\Models\BahanBaku;
use App\Models\User;
use App\Support\Rbac;

/** Modul 6 Bahan Baku (+ mutasi stok): admin & staf_gudang CRUD; peran lain baca. */
class BahanBakuPolicy extends BaseSppgPolicy
{
    protected string $module = 'bahan-baku';

    /**
     * Riwayat mutasi stok (harga satuan, pemasok, pencatat) lebih sempit daripada R bahan baku: hanya peran pada
     * config('rbac.aksi_peran.lihat-mutasi-stok') (admin, kepala_sppg, staf_gudang, akuntan), ditambah hak R modul dan
     * SPPG yang sama (admin lintas SPPG). ahli_gizi/investor tetap boleh melihat daftar/detail bahan baku.
     */
    public function lihatMutasi(User $user, BahanBaku $bahan): bool
    {
        return Rbac::canAction($user, 'lihat-mutasi-stok') && $this->view($user, $bahan);
    }
}
