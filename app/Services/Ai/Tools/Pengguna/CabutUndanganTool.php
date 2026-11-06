<?php

namespace App\Services\Ai\Tools\Pengguna;

use App\Enums\TipeUndangan;
use App\Models\AiDraft;
use App\Models\Invitation;
use App\Models\User;
use App\Services\Ai\Eksekusi\RingkasanDraf;
use App\Services\Ai\Eksekusi\SpesifikasiEksekusi;
use Illuminate\Database\Eloquent\Model;

/**
 * `cabut_undangan` (Admin): DRAF pencabutan SATU undangan pengguna yang masih aktif (target_id dari
 * cari_undangan_pengguna). Aksi berbahaya: konfirmasi dua langkah. Dijalankan sebagai DELETE /pengguna/undangan/{id}
 * (hak sama dengan REST: Policy create User).
 */
class CabutUndanganTool extends PenggunaDraftTool
{
    protected string $name = 'cabut_undangan';

    protected string $description = 'Siapkan DRAF pencabutan satu undangan pengguna yang masih aktif. target_id = ID dari cari_undangan_pengguna.';

    protected string $action = 'D';

    protected ?string $model = Invitation::class;

    public function isAvailableFor(User $user): bool
    {
        return parent::isAvailableFor($user) && $this->gate($user)->allows('create', User::class);
    }

    protected function formRequest(): ?string
    {
        return null;
    }

    protected function fields(): array
    {
        return ['properties' => [], 'required' => []];
    }

    public function draftResource(): ?string
    {
        return 'undangan';
    }

    protected function routeParameter(): ?string
    {
        return 'invitation';
    }

    protected function findTarget(User $user, int $id): ?Model
    {
        return Invitation::query()->aktif()->where('tipe', TipeUndangan::Pengguna->value)->find($id);
    }

    protected function izinkanTarget(User $user, Model $target): bool
    {
        return $this->gate($user)->allows('create', User::class);
    }

    protected function sidikTarget(Model $target): ?string
    {
        /** @var Invitation $target */
        return substr(hash('sha256', implode('|', [$target->getKey(), (string) $target->used_at, (string) $target->revoked_at, (string) $target->expires_at])), 0, 16);
    }

    protected function namaTarget(Model $record): string
    {
        /** @var Invitation $record */
        return 'Undangan #'.$record->getKey().' ('.self::labelPeran($record->role, $record->unit).')';
    }

    public function eksekusi(): ?SpesifikasiEksekusi
    {
        return SpesifikasiEksekusi::rest('api.v1.pengguna.undangan.destroy', 'DELETE', 'invitation', bisaUbah: false);
    }

    public function berbahaya(AiDraft $draft): bool
    {
        return true;
    }

    public function ringkasan(User $user, AiDraft $draft): RingkasanDraf
    {
        /** @var Invitation|null $inv */
        $inv = $this->findTarget($user, (int) $draft->target_id);

        return new RingkasanDraf('Cabut undangan pengguna', $inv === null ? ['Undangan tidak ditemukan atau sudah tidak aktif.'] : [
            'Peran: '.self::labelPeran($inv->role, $inv->unit),
            'SPPG tujuan: '.($inv->sppg_id === null ? 'tidak terikat SPPG' : (self::namaSppg($inv->sppg_id) ?? '-')),
            'Berlaku sampai: '.$inv->expires_at->timezone((string) config('operasional.timezone', 'Asia/Jakarta'))->format('d/m/Y H:i').' WIB',
        ], $inv === null ? '#'.(int) $draft->target_id : $this->namaTarget($inv), 'Tautan undangan ini tidak dapat dipakai lagi setelah dicabut.');
    }
}
