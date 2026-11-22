<?php

namespace App\Services\Ai\Tools\Pengguna;

use App\Http\Requests\Pengguna\UpdatePenggunaRequest;
use App\Models\AiDraft;
use App\Models\User;
use App\Services\Ai\Eksekusi\RingkasanDraf;
use App\Services\Ai\Eksekusi\SpesifikasiEksekusi;
use Illuminate\Database\Eloquent\Model;

/**
 * `nonaktifkan_pengguna` (Admin): DRAF menonaktifkan SATU akun (is_active=false). Berbahaya: dua langkah.
 * PATCH /pengguna/{id} + ai_draft_id; UserAccountService menolak menonaktifkan diri sendiri / admin aktif terakhir.
 */
class NonaktifkanPenggunaTool extends PenggunaDraftTool
{
    protected string $name = 'nonaktifkan_pengguna';

    protected string $description = 'Siapkan DRAF menonaktifkan satu akun (tidak bisa masuk). target_id dari cari_pengguna.';

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
        return ['is_active' => false];
    }

    protected function tolakDraf(User $user, ?Model $target, array $payload): ?string
    {
        return match (true) {
            $target !== null && $target->is($user) => 'Anda tidak dapat menonaktifkan akun Anda sendiri.',
            $target instanceof User && ! $target->is_active => 'Akun ini sudah nonaktif.',
            default => null,
        };
    }

    public function eksekusi(): ?SpesifikasiEksekusi
    {
        return SpesifikasiEksekusi::rest('api.v1.pengguna.update', 'PATCH', 'user', kirimAiDraftId: true);
    }

    public function berbahaya(AiDraft $draft): bool
    {
        return true;
    }

    public function ringkasan(User $user, AiDraft $draft): RingkasanDraf
    {
        $target = $this->findTarget($user, (int) $draft->target_id);

        return new RingkasanDraf('Nonaktifkan pengguna', ['Status baru: Nonaktif'],
            $target === null ? '#'.(int) $draft->target_id.' (tidak ditemukan)' : $this->namaTarget($target),
            'Pengguna tidak dapat masuk dan semua sesi login-nya dicabut.');
    }
}
