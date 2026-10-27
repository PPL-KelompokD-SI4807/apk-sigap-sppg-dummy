<?php

namespace App\Policies;

use App\Models\PembelianBahan;
use App\Models\User;
use App\Support\Rbac;
use Illuminate\Database\Eloquent\Model;

/**
 * Pembelian bahan (R-06b-2): staf gudang CRU, akuntan/admin/kepala R, investor tanpa akses (nota privat).
 * Verifikasi hanya akuntan (hak 'keputusan') pada SPPG-nya sendiri dan BUKAN pencatat pembelian itu
 * (pemisahan tugas). Tidak ada hapus.
 */
class PembelianBahanPolicy extends BaseSppgPolicy
{
    protected string $module = 'pembelian-bahan';

    protected ?string $ownerColumn = 'dicatat_oleh';

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function restore(User $user, Model $model): bool
    {
        return false;
    }

    /**
     * Tambah lampiran (nota) ke pembelian yang ADA lewat POST /lampiran (config unggahan.modul.pembelian-bahan.ability).
     * Hak ubah + status belum terverifikasi: nota pembelian terverifikasi final (R-06b-2 M1). Pemeriksaan ulang di
     * bawah kunci baris ada di PenjagaLampiranInduk. PATCH tetap memakai `update` agar galatnya 409 terstruktur.
     */
    public function lampirkan(User $user, PembelianBahan $pembelian): bool
    {
        return $this->update($user, $pembelian) && $pembelian->status->bisaDiubah();
    }

    public function verifikasi(User $user, PembelianBahan $pembelian): bool
    {
        return Rbac::canDecide($user, $this->module)
            && ! $user->isAdmin()
            && $this->sameSppg($user, $pembelian)
            && (int) $pembelian->dicatat_oleh !== (int) $user->getKey();
    }
}
