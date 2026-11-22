<?php

namespace App\Policies;

use App\Models\Pengeluaran;
use App\Models\User;
use App\Support\Rbac;

/**
 * Modul 14 Data Keuangan — pengeluaran operasional (Tabel 5): akuntan CRUD, admin & kepala_sppg R,
 * peran lain tanpa akses. Isolasi SPPG dari BaseSppgPolicy. Investor R + memutuskan pengajuan (R-06b-2,
 * hak 'keputusan' modul keuangan) pada SPPG aktif miliknya; admin/kepala/akuntan tidak pernah memutuskan.
 */
class PengeluaranPolicy extends BaseSppgPolicy
{
    protected string $module = 'keuangan';

    protected ?string $ownerColumn = 'dicatat_oleh';

    /**
     * Tambah lampiran ke pengeluaran yang ADA lewat POST /lampiran (config unggahan.modul.keuangan-pengeluaran.ability).
     * Hak ubah dan bukan pengeluaran hasil verifikasi pembelian (R-06b-2 M2). Status `disetujui` serta kenaikan versi
     * diperiksa di PenjagaLampiranInduk (409). PATCH tetap memakai `update` (BudgetService mengembalikan 409).
     */
    public function lampirkan(User $user, Pengeluaran $pengeluaran): bool
    {
        return $this->update($user, $pengeluaran) && $pengeluaran->pembelian_bahan_id === null;
    }

    public function putuskan(User $user, Pengeluaran $pengeluaran): bool
    {
        return Rbac::canDecide($user, $this->module) && ! $user->isAdmin() && $this->sameSppg($user, $pengeluaran);
    }
}
