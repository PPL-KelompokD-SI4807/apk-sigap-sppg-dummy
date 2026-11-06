<?php

namespace App\Services\Ai\Tools\Pengguna;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Models\Scopes\SppgScope;
use App\Models\Sppg;
use App\Models\User;
use App\Services\Ai\Tools\WriteDraftTool;
use Illuminate\Database\Eloquent\Model;

/**
 * Dasar tool tulis modul 1 (Data Pengguna) untuk Agen AI. Ketersediaan HANYA dari Rbac + Policy yang sama dengan REST
 * (config/rbac.php: pengguna CRUD hanya Admin), bukan daftar peran tersendiri; draf Admin tidak terikat SPPG.
 *
 * - Draf wajib lolos Form Request saat dibuat (wajibValid): isian yang kurang (mis. SPPG tujuan) membuat model
 *   bertanya ulang alih-alih menebak.
 * - Target (akun) dicari dari basis data atas nama pengguna (SppgScope + Policy); nama target di ringkasan dari DB.
 * - Sidik target = peran/unit/SPPG/status/terhapus, sehingga perubahan akun setelah draf dibuat -> "data berubah".
 */
abstract class PenggunaDraftTool extends WriteDraftTool
{
    protected string $module = 'pengguna';

    protected ?string $model = User::class;

    protected function butuhSppg(): bool
    {
        return false;
    }

    protected function wajibValid(): bool
    {
        return true;
    }

    protected function labelSumberDaya(): string
    {
        return 'pengguna';
    }

    protected function routeParameter(): ?string
    {
        return $this->action === 'C' ? null : 'user';
    }

    protected function findTarget(User $user, int $id): ?Model
    {
        return User::query()->find($id);
    }

    protected function sidikTarget(Model $target): ?string
    {
        /** @var User $target */
        return substr(hash('sha256', implode('|', [
            $target->getKey(), $target->role?->value, $target->unit?->value, (string) $target->sppg_id,
            $target->is_active ? '1' : '0', $target->deleted_at === null ? '' : 'x',
        ])), 0, 16);
    }

    protected function namaTarget(Model $record): string
    {
        /** @var User $record */
        return self::satuBaris((string) $record->name, 80).' (#'.$record->getKey().', '.$record->role->label().')';
    }

    /** Hapus/kosongkan unit & SPPG yang tidak relevan untuk peran (sesuai InvitationService/UserAccountService). */
    protected static function normalisasiPeran(array $payload): array
    {
        $role = Role::tryFrom((string) ($payload['role'] ?? ''));

        if ($role === null) {
            return $payload;
        }

        if ($role !== Role::Relawan) {
            unset($payload['unit']);
        }

        if ($role->tanpaSppgId()) {
            unset($payload['sppg_id']);
        }

        return $payload;
    }

    /** "Nama SPPG (kode)" dari basis data untuk SPPG yang belum dihapus, atau null. */
    protected static function namaSppg(mixed $id): ?string
    {
        if (! is_numeric($id)) {
            return null;
        }

        $sppg = Sppg::query()->withoutGlobalScope(SppgScope::class)->whereKey((int) $id)->whereNull('deleted_at')->first(['id', 'kode', 'nama']);

        return $sppg === null ? null : trim($sppg->nama.' ('.$sppg->kode.')');
    }

    protected static function labelPeran(mixed $role, mixed $unit = null): string
    {
        $r = Role::tryFrom((string) $role);
        $u = RelawanUnit::tryFrom((string) $unit);

        return $r === null ? '-' : $r->label().($r === Role::Relawan && $u !== null ? ' unit '.$u->label() : '');
    }

    /** Skema parameter peran/unit/SPPG (argumen terlindungi yang diizinkan EKSPLISIT untuk tool pengguna). */
    protected static function skemaPeran(): array
    {
        return [
            'role' => ['type' => 'string', 'enum' => Role::values(), 'description' => 'Peran akun.'],
            'unit' => ['type' => 'string', 'enum' => RelawanUnit::values(), 'description' => 'Wajib bila role relawan.'],
            'sppg_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'ID SPPG dari cari_sppg; wajib selain admin/investor. Belum disebut pengguna: tanyakan.'],
        ];
    }

    public function parameters(): array
    {
        $parameters = parent::parameters();
        // Isian pengguna jelas (peran/akun), tidak ada tulisan tangan yang perlu keyakinan per kolom.
        unset($parameters['properties']['keyakinan']);

        return $parameters;
    }
}
