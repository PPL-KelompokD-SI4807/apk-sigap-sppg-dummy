<?php

namespace App\Services\Users;

use App\Enums\Role;
use App\Models\Invitation;
use App\Models\Scopes\SppgScope;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Logika akun pengguna oleh Admin. Penjaga keamanan: admin tidak dapat menonaktifkan, menghapus,
 * atau mengubah peran akunnya sendiri, dan admin aktif terakhir tidak pernah dapat hilang.
 * Perubahan role/unit/sppg_id/is_active otomatis mencabut token (User::booted).
 */
class UserAccountService
{
    public function __construct(private readonly KepemilikanSppgService $kepemilikan) {}

    /** @param array<string, mixed> $data hasil Form Request (name, role, unit, sppg_id, phone, is_active) */
    public function create(array $data): User
    {
        $role = Role::from($data['role']);

        return User::query()->create([
            'name' => $data['name'],
            'role' => $role,
            'unit' => $role === Role::Relawan ? ($data['unit'] ?? null) : null,
            'sppg_id' => $role->tanpaSppgId() ? null : ($data['sppg_id'] ?? null),
            'phone' => $data['phone'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, User $target, array $data): User
    {
        $role = array_key_exists('role', $data) ? Role::from($data['role']) : $target->role;
        $active = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $target->is_active;

        if ($actor->is($target)) {
            if ($role !== $target->role) {
                $this->fail('Anda tidak dapat mengubah peran akun Anda sendiri.');
            }

            if (! $active && $target->is_active) {
                $this->fail('Anda tidak dapat menonaktifkan akun Anda sendiri.');
            }
        }

        if ($target->isAdmin() && $target->is_active && ($role !== Role::Admin || ! $active)) {
            $this->assertNotLastAdmin($target);
        }

        $target->fill([
            'name' => $data['name'] ?? $target->name,
            'phone' => array_key_exists('phone', $data) ? $data['phone'] : $target->phone,
            'role' => $role,
            'is_active' => $active,
            'unit' => $role === Role::Relawan ? (array_key_exists('unit', $data) ? $data['unit'] : $target->unit?->value) : null,
            'sppg_id' => $role->tanpaSppgId() ? null : (array_key_exists('sppg_id', $data) ? $data['sppg_id'] : $target->sppg_id),
        ]);
        $bukanInvestorLagi = $target->isDirty('role') && $target->getOriginal('role') === Role::Investor;
        $target->save();

        if ($bukanInvestorLagi) {
            $this->kepemilikan->hapusSemua($actor, $target);
        }

        return $target;
    }

    public function delete(User $actor, User $target): void
    {
        if ($actor->is($target)) {
            $this->fail('Anda tidak dapat menghapus akun Anda sendiri.');
        }

        if ($target->isAdmin() && $target->is_active) {
            $this->assertNotLastAdmin($target);
        }

        DB::transaction(function () use ($target): void {
            Invitation::query()->aktif()->where('user_id', $target->getKey())->update(['revoked_at' => now()]);
            $target->delete();
        });
    }

    private function assertNotLastAdmin(User $target): void
    {
        $others = User::query()->withoutGlobalScope(SppgScope::class)
            ->where('role', Role::Admin->value)
            ->where('is_active', true)
            ->whereKeyNot($target->getKey())
            ->lockForUpdate()
            ->count();

        if ($others === 0) {
            $this->fail('Tidak dapat menonaktifkan, menghapus, atau menurunkan admin aktif terakhir.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['user' => [$message]]);
    }
}
