<?php

namespace App\Policies;

use App\Models\Pemasok;
use App\Models\User;

/**
 * Modul Pemasok: matriks CRUD Tabel 5 (config/rbac.php, slug 'pemasok') + isolasi SPPG dari BaseSppgPolicy.
 * Riwayat harga (R-06b-1) mengikuti modul yang sama: baca = R, catat harga = C (append-only).
 */
class PemasokPolicy extends BaseSppgPolicy
{
    protected string $module = 'pemasok';

    public function lihatHarga(User $user, Pemasok $pemasok): bool
    {
        return $this->view($user, $pemasok);
    }

    public function catatHarga(User $user, Pemasok $pemasok): bool
    {
        return $this->canLetter($user, 'C') && $this->sameSppg($user, $pemasok);
    }
}
