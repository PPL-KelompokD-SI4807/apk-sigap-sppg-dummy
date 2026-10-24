<?php

namespace App\Policies;

use App\Models\Lampiran;
use App\Models\User;
use App\Services\Storage\SecureUploadService;
use Illuminate\Support\Facades\Gate;

/**
 * Lampiran tidak punya baris sendiri di matriks Tabel 5: hak MEWARISI record induknya
 * (Policy modul induk, mis. BuktiDistribusiPolicy::view). Lampiran belum terikat (unggahan tertunda)
 * hanya dapat dilihat pengunggahnya. Induk di luar allowlist
 * config('unggahan.modul'), terhapus, atau milik SPPG lain -> ditolak. Lampiran tidak dapat
 * diubah/dihapus lewat API (jejak audit).
 */
class LampiranPolicy
{
    public function __construct(private readonly SecureUploadService $unggahan) {}

    public function view(User $user, Lampiran $lampiran): bool
    {
        // Belum terikat ke induk: hanya pengunggahnya yang dapat melihat (pratinjau sebelum simpan).
        if ($lampiran->lampiranable_type === null) {
            return $lampiran->uploaded_by !== null && (int) $lampiran->uploaded_by === (int) $user->getKey();
        }

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
