<?php

namespace App\Services\Users;

use App\Enums\Role;
use App\Models\User;
use App\Services\Auth\AuditAuth;
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
     * Ganti seluruh kepemilikan investor dengan $sppgIds (sudah divalidasi Form Request).
     *
     * @param  list<int>  $sppgIds
     * @return list<array{id: int, kode: string, nama: string}>
     */
    public function atur(User $admin, User $investor, array $sppgIds): array
    {
        $baru = array_values(array_unique(array_map('intval', $sppgIds)));
        sort($baru);

        DB::transaction(function () use ($admin, $investor, $baru): void {
            $terkunci = User::query()->withoutGlobalScopes()->lockForUpdate()->find($investor->getKey());

            if ($terkunci === null || $terkunci->role !== Role::Investor) {
                throw ValidationException::withMessages(['user' => ['Kepemilikan SPPG hanya untuk pengguna berperan investor.']]);
            }

            $lama = DB::table('investor_sppg')->where('user_id', $terkunci->getKey())
                ->orderBy('sppg_id')->pluck('sppg_id')->map(fn ($id) => (int) $id)->all();

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
        return $this->atur($admin, $investor, [...$this->idMilik($investor), $sppgId]);
    }

    /**
     * Lepas satu SPPG dari investor (idempoten: tidak tertaut = tanpa perubahan/audit).
     *
     * @return list<array{id: int, kode: string, nama: string}>
     */
    public function lepas(User $admin, User $investor, int $sppgId): array
    {
        return $this->atur($admin, $investor, array_values(array_diff($this->idMilik($investor), [$sppgId])));
    }

    /** @return list<int> */
    private function idMilik(User $investor): array
    {
        return DB::table('investor_sppg')->where('user_id', $investor->getKey())
            ->pluck('sppg_id')->map(fn ($id) => (int) $id)->all();
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
