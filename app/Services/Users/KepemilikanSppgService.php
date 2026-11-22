<?php

namespace App\Services\Users;

use App\Enums\Role;
use App\Models\User;
use App\Services\Auth\AuditAuth;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Kepemilikan SPPG investor (R-06a, pivot investor_sppg). Hanya dipanggil setelah Policy Admin.
 * Perubahan berlaku pada permintaan berikutnya (SppgAktif membaca pivot tiap permintaan), sehingga
 * SPPG yang dicabut langsung tidak dapat diakses walau Mini App masih menyimpannya.
 */
class KepemilikanSppgService
{
    public const AUDIT_DIUBAH = 'pengguna.kepemilikan_sppg_diubah';

    /**
     * SPPG milik investor (belum dihapus), terurut nama.
     *
     * @return list<array{id: int, kode: string, nama: string}>
     */
    public function daftar(User $investor): array
    {
        return DB::table('investor_sppg')
            ->join('sppgs', 'sppgs.id', '=', 'investor_sppg.sppg_id')
            ->where('investor_sppg.user_id', $investor->getKey())
            ->whereNull('sppgs.deleted_at')
            ->orderBy('sppgs.nama')->orderBy('sppgs.id')
            ->get(['sppgs.id', 'sppgs.kode', 'sppgs.nama'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'kode' => (string) $r->kode, 'nama' => (string) $r->nama])
            ->all();
    }

    /**
     * Ganti seluruh kepemilikan investor (sudah divalidasi Form Request).
     *
     * $target = daftar ID pengganti penuh, ATAU closure fn (list<int> $lama): list<int> yang dihitung
     * DI DALAM transaksi setelah baris investor dikunci (R06a-S-01). tautkan()/lepas() memakai closure
     * agar dua perubahan bersamaan tidak saling menimpa (mis. SPPG yang baru dilepas hidup kembali
     * karena tautkan() membaca himpunan lama sebelum kunci).
     *
     * @param  list<int>|Closure(list<int>): array<int, int>  $target
     * @return list<array{id: int, kode: string, nama: string}>
     */
    public function atur(User $admin, User $investor, array|Closure $target): array
    {
        DB::transaction(function () use ($admin, $investor, $target): void {
            $terkunci = User::query()->withoutGlobalScopes()->lockForUpdate()->find($investor->getKey());

            if ($terkunci === null || $terkunci->role !== Role::Investor) {
                throw ValidationException::withMessages(['user' => ['Kepemilikan SPPG hanya untuk pengguna berperan investor.']]);
            }

            // Dibaca setelah kunci: semua perubahan kepemilikan investor ini berbaris di kunci yang sama.
            $lama = DB::table('investor_sppg')->where('user_id', $terkunci->getKey())
                ->orderBy('sppg_id')->pluck('sppg_id')->map(fn ($id) => (int) $id)->all();

            $baru = array_values(array_unique(array_map('intval', $target instanceof Closure ? $target($lama) : $target)));
            sort($baru);

            $dihapus = array_values(array_diff($lama, $baru));
            $ditambah = array_values(array_diff($baru, $lama));

            if ($dihapus !== []) {
                DB::table('investor_sppg')->where('user_id', $terkunci->getKey())->whereIn('sppg_id', $dihapus)->delete();
            }

            $now = now();
            DB::table('investor_sppg')->insert(array_map(fn (int $id) => [
                'user_id' => $terkunci->getKey(),
                'sppg_id' => $id,
                'created_by' => $admin->getKey(),
                'created_at' => $now,
                'updated_at' => $now,
            ], $ditambah));

            if ($dihapus !== [] || $ditambah !== []) {
                AuditAuth::catat($terkunci, self::AUDIT_DIUBAH, [
                    'sebelum' => $lama,
                    'sesudah' => $baru,
                    'ditambah' => $ditambah,
                    'dihapus' => $dihapus,
                ], $admin);
            }
        });

        return $this->daftar($investor);
    }

    /**
     * Tautkan satu SPPG ke investor (idempoten: sudah tertaut = tanpa perubahan/audit).
     *
     * @return list<array{id: int, kode: string, nama: string}>
     */
    public function tautkan(User $admin, User $investor, int $sppgId): array
    {
        return $this->atur($admin, $investor, fn (array $lama): array => [...$lama, $sppgId]);
    }

    /**
     * Lepas satu SPPG dari investor (idempoten: tidak tertaut = tanpa perubahan/audit).
     *
     * @return list<array{id: int, kode: string, nama: string}>
     */
    public function lepas(User $admin, User $investor, int $sppgId): array
    {
        return $this->atur($admin, $investor, fn (array $lama): array => array_diff($lama, [$sppgId]));
    }

    /** Peran berubah dari investor: kepemilikan lama dihapus agar tidak hidup lagi bila peran dikembalikan. */
    public function hapusSemua(User $actor, User $user): void
    {
        $lama = DB::table('investor_sppg')->where('user_id', $user->getKey())
            ->orderBy('sppg_id')->pluck('sppg_id')->map(fn ($id) => (int) $id)->all();

        if ($lama === []) {
            return;
        }

        DB::table('investor_sppg')->where('user_id', $user->getKey())->delete();
        AuditAuth::catat($user, self::AUDIT_DIUBAH, ['sebelum' => $lama, 'sesudah' => [], 'ditambah' => [], 'dihapus' => $lama, 'alasan' => 'peran_berubah'], $actor);
    }
}
