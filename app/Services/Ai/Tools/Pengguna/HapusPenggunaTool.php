<?php

namespace App\Services\Ai\Tools\Pengguna;

use App\Models\AiDraft;
use App\Models\User;
use App\Services\Ai\Eksekusi\RingkasanDraf;
use App\Services\Ai\Eksekusi\SpesifikasiEksekusi;
use Illuminate\Database\Eloquent\Model;

/**
 * `hapus_pengguna` (Admin): DRAF penghapusan (lunak) SATU akun. Berbahaya: dua langkah. DELETE /pengguna/{id};
 * UserAccountService menolak menghapus diri sendiri / admin aktif terakhir. Tidak ada aksi massal.
 */
class HapusPenggunaTool extends PenggunaDraftTool
{
    protected string $name = 'hapus_pengguna';

    protected string $description = 'Siapkan DRAF penghapusan satu akun. target_id dari cari_pengguna.';

    protected string $action = 'D';

    protected function formRequest(): ?string
    {
        return null;
    }

    protected function fields(): array
    {
        return ['properties' => [], 'required' => []];
    }

    protected function toPayload(User $user, array $arguments): array
    {
        return [];
    }

    protected function tolakDraf(User $user, ?Model $target, array $payload): ?string
    {
        return $target !== null && $target->is($user) ? 'Anda tidak dapat menghapus akun Anda sendiri.' : null;
    }

    public function eksekusi(): ?SpesifikasiEksekusi
    {
        return SpesifikasiEksekusi::rest('api.v1.pengguna.destroy', 'DELETE', 'user', bisaUbah: false);
    }

    public function berbahaya(AiDraft $draft): bool
    {
        return true;
    }

    public function ringkasan(User $user, AiDraft $draft): RingkasanDraf
    {
        $target = $this->findTarget($user, (int) $draft->target_id);

        return new RingkasanDraf('Hapus pengguna', ['Akun dihapus (lunak), semua sesi dan undangan aktifnya dicabut.'],
            $target === null ? '#'.(int) $draft->target_id.' (tidak ditemukan)' : $this->namaTarget($target),
            'Akun ini akan DIHAPUS dan tidak dapat masuk lagi.');
    }
}
