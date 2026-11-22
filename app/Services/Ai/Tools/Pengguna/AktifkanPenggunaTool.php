<?php

namespace App\Services\Ai\Tools\Pengguna;

use App\Http\Requests\Pengguna\UpdatePenggunaRequest;
use App\Models\AiDraft;
use App\Models\User;
use App\Services\Ai\Eksekusi\RingkasanDraf;
use App\Services\Ai\Eksekusi\SpesifikasiEksekusi;
use Illuminate\Database\Eloquent\Model;

/**
 * `aktifkan_pengguna` (Admin): DRAF mengaktifkan kembali SATU akun nonaktif (is_active=true). Satu langkah konfirmasi.
 * PATCH /pengguna/{id} + ai_draft_id (Policy + Form Request + UserAccountService sama dengan REST).
 */
class AktifkanPenggunaTool extends PenggunaDraftTool
{
    protected string $name = 'aktifkan_pengguna';

    protected string $description = 'Siapkan DRAF mengaktifkan kembali satu akun nonaktif. target_id dari cari_pengguna.';

    protected string $action = 'U';

    protected function formRequest(): ?string
    {
        return UpdatePenggunaRequest::class;
    }

    protected function fields(): array
    {
        return ['properties' => [], 'required' => []];
    }

    protected function toPayload(User $user, array $arguments): array
    {
        return ['is_active' => true];
    }

    protected function tolakDraf(User $user, ?Model $target, array $payload): ?string
    {
        return $target instanceof User && $target->is_active ? 'Akun ini sudah aktif.' : null;
    }

    public function eksekusi(): ?SpesifikasiEksekusi
    {
        return SpesifikasiEksekusi::rest('api.v1.pengguna.update', 'PATCH', 'user', kirimAiDraftId: true);
    }

    public function ringkasan(User $user, AiDraft $draft): RingkasanDraf
    {
        $target = $this->findTarget($user, (int) $draft->target_id);

        return new RingkasanDraf('Aktifkan pengguna', ['Status baru: Aktif (pengguna dapat masuk lagi)'],
            $target === null ? '#'.(int) $draft->target_id.' (tidak ditemukan)' : $this->namaTarget($target));
    }
}
