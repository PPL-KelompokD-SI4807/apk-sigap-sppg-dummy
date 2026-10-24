<?php

namespace App\Policies;

use App\Models\Lampiran;
use App\Models\User;
use App\Services\Storage\SecureUploadService;
use Illuminate\Support\Facades\Gate;

/**
 * Lampiran tidak punya baris sendiri di matriks Tabel 5: hak MEWARISI record induknya
 * (Policy modul induk, mis. BuktiDistribusiPolicy::view). Induk di luar allowlist
 * config('unggahan.modul'), terhapus, atau milik SPPG lain -> ditolak. Lampiran tidak dapat
 * diubah/dihapus lewat API (jejak audit).
 */
class LampiranPolicy
{
    public function __construct(private readonly SecureUploadService $unggahan) {}

    public function view(User $user, Lampiran $lampiran): bool
    {
        $induk = $this->unggahan->induk($lampiran);

        return $induk !== null && Gate::forUser($user)->allows('view', $induk);
    }

    public function viewAny(User $user): bool
    {
        return false;
    }

    public function update(User $user, Lampiran $lampiran): bool
    {
        return false;
    }

    public function delete(User $user, Lampiran $lampiran): bool
    {
        return false;
    }
}
