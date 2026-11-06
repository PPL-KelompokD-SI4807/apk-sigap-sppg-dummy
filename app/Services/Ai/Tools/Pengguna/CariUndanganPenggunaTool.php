<?php

namespace App\Services\Ai\Tools\Pengguna;

use App\Enums\Role;
use App\Enums\TipeUndangan;
use App\Models\Invitation;
use App\Models\User;
use App\Services\Ai\Tools\MenerimaArgumenTerlindungi;
use App\Services\Ai\Tools\ReadTool;
use Illuminate\Support\Facades\Gate;

/**
 * `cari_undangan_pengguna` (Admin): daftar undangan pengguna yang MASIH AKTIF (id, peran, unit, SPPG, kedaluwarsa)
 * untuk memilih target cabut_undangan. Hak sama dengan GET /pengguna/undangan (Policy create User).
 * Tidak pernah memuat token, token_hash, atau tautan.
 */
class CariUndanganPenggunaTool extends ReadTool implements MenerimaArgumenTerlindungi
{
    protected string $name = 'cari_undangan_pengguna';

    protected string $description = 'Daftar undangan pengguna yang masih aktif (tanpa tautan).';

    protected string $module = 'pengguna';

    protected ?string $model = User::class;

    public function isAvailableFor(User $user): bool
    {
        return parent::isAvailableFor($user) && $this->allowsClass($user);
    }

    /** Hanya filter `role` (enum Role, baca saja) yang diizinkan; argumen terlindungi lain tetap dibuang. */
    public function argumenTerlindungiSah(): array
    {
        return ['role'];
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => [], 'properties' => [
            'role' => ['type' => 'string', 'enum' => Role::values(), 'description' => 'Filter peran.'],
        ]];
    }

    protected function handle(User $user, array $arguments): array
    {
        $baris = Invitation::query()->aktif()->with('sppg:id,kode,nama')
            ->where('tipe', TipeUndangan::Pengguna->value)
            ->when(Role::tryFrom((string) ($arguments['role'] ?? '')) !== null, fn ($q) => $q->where('role', $arguments['role']))
            ->orderByDesc('id')->limit(20)->get();

        return ['hasil' => $baris->map(fn (Invitation $i) => [
            'id' => $i->id,
            'role' => $i->role,
            'unit' => $i->unit,
            'sppg' => $i->sppg === null ? null : $i->sppg->kode.' '.$i->sppg->nama,
            'berlaku_sampai' => $i->expires_at->toIso8601String(),
        ])->all()];
    }

    private function allowsClass(User $user): bool
    {
        return Gate::forUser($user)->allows('create', User::class);
    }
}
