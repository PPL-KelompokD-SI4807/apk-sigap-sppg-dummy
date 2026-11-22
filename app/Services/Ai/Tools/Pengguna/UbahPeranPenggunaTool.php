<?php

namespace App\Services\Ai\Tools\Pengguna;

use App\Enums\Role;
use App\Http\Requests\Pengguna\UpdatePenggunaRequest;
use App\Models\AiDraft;
use App\Models\User;
use App\Services\Ai\Eksekusi\RingkasanDraf;
use App\Services\Ai\Eksekusi\SpesifikasiEksekusi;
use App\Services\Ai\Tools\MenerimaArgumenTerlindungi;
use Illuminate\Database\Eloquent\Model;

/**
 * `ubah_peran_pengguna` (Admin): DRAF perubahan peran (+ unit relawan, SPPG) SATU akun. Berbahaya: dua langkah.
 * Dijalankan sebagai PATCH /pengguna/{id} + ai_draft_id (UpdatePenggunaRequest + UserPolicy + UserAccountService:
 * admin tidak dapat mengubah peran dirinya, admin aktif terakhir tidak dapat diturunkan; token target dicabut).
 */
class UbahPeranPenggunaTool extends PenggunaDraftTool implements MenerimaArgumenTerlindungi
{
    protected string $name = 'ubah_peran_pengguna';

    protected string $description = 'Siapkan DRAF perubahan peran satu akun. target_id dari cari_pengguna; isi role (+unit relawan, sppg_id bila perlu).';

    protected string $action = 'U';

    public function argumenTerlindungiSah(): array
    {
        return ['role', 'unit', 'sppg_id'];
    }

    protected function formRequest(): ?string
    {
        return UpdatePenggunaRequest::class;
    }

    protected function fields(): array
    {
        return ['properties' => self::skemaPeran(), 'required' => ['role']];
    }

    protected function toPayload(User $user, array $arguments): array
    {
        return self::normalisasiPeran(array_intersect_key($arguments, array_flip(['role', 'unit', 'sppg_id'])));
    }

    protected function tolakDraf(User $user, ?Model $target, array $payload): ?string
    {
        return $target !== null && $target->is($user) ? 'Anda tidak dapat mengubah peran akun Anda sendiri.' : null;
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
        /** @var User|null $target */
        $target = $this->findTarget($user, (int) $draft->target_id);
        $p = (array) $draft->payload;
        $peringatan = (($p['role'] ?? null) === Role::Admin->value ? 'PERINGATAN: peran Admin memberi akses penuh ke SEMUA SPPG dan data pengguna. ' : '')
            .'Semua sesi login pengguna ini akan dicabut; ia harus masuk ulang.';

        return new RingkasanDraf('Ubah peran pengguna', [
            'Peran saat ini: '.($target === null ? '-' : self::labelPeran($target->role->value, $target->unit?->value)),
            'Peran baru: '.self::labelPeran($p['role'] ?? null, $p['unit'] ?? null),
            ...(array_key_exists('sppg_id', $p) ? ['SPPG: '.(self::namaSppg($p['sppg_id']) ?? 'tidak ditemukan')] : []),
        ], $target === null ? '#'.(int) $draft->target_id.' (tidak ditemukan)' : $this->namaTarget($target), $peringatan);
    }
}
