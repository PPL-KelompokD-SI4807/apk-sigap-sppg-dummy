<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Rbac;

/**
 * Jadwal produksi (modul 9). Relawan unit dapur CRU atas jadwal produksi; hanya jadwal buatannya
 * sendiri ($ownerColumn = dibuat_oleh) pada hari yang sama untuk update.
 */
class JadwalProduksiPolicy extends BaseSppgPolicy
{
    protected string $module = 'jadwal';

    protected ?string $jenis = 'produksi';

    protected ?string $ownerColumn = 'dibuat_oleh';

    /**
     * Daftar ringkas (id, tanggal, menu, target, status) untuk pilihan pada form distribusi:
     * semua peran dengan hak R modul jadwal, TANPA pembatasan sub-jenis unit relawan.
     */
    public function viewRingkas(User $user): bool
    {
        return Rbac::can($user, 'jadwal', 'R') && ! Rbac::isSelfOnly($user, 'jadwal', 'R');
    }
}
