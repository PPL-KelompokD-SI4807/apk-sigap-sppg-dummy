<?php

namespace App\Services\Telegram\Bot\Layar;

use App\Enums\Role;
use App\Models\Scopes\SppgScope;
use App\Models\User;
use App\Services\Telegram\Bot\BotLayar;

/**
 * Tim / Pengguna (layar `tm`): HANYA hitungan pengguna aktif per peran pada SPPG terpilih (sppg_id eksplisit).
 * Tanpa nama, kontak, username, atau akun Telegram (keputusan 4). Investor tidak terikat satu SPPG (pivot) sehingga
 * tidak dihitung. Policy viewAny User menolak peran yang hanya R* (profil sendiri).
 */
final class LayarTim extends LayarModulDasar
{
    public function id(): string
    {
        return 'tm';
    }

    public function modul(): ?string
    {
        return 'pengguna';
    }

    protected function judul(): string
    {
        return 'Tim';
    }

    protected function rute(): ?string
    {
        return '/pengguna';
    }

    public function izinkan(User $user, ?int $sppgId): bool
    {
        return $this->bolehLihat($user, User::class);
    }

    public function render(User $user, ?int $sppgId, KonteksLayar $konteks): BotLayar
    {
        $hitungan = User::query()->withoutGlobalScope(SppgScope::class)
            ->where('sppg_id', (int) $sppgId)->where('is_active', true)
            ->selectRaw('role, count(*) as jumlah')->groupBy('role')->pluck('jumlah', 'role');

        $isi = [];
        $total = 0;

        foreach (Role::cases() as $peran) {
            $jumlah = (int) ($hitungan[$peran->value] ?? 0);

            if ($jumlah > 0) {
                $isi[] = '- '.self::e($peran->label().': '.FormatLayar::angka($jumlah));
                $total += $jumlah;
            }
        }

        array_unshift($isi, self::e('Pengguna aktif: '.FormatLayar::angka($total)));

        return $this->susun($user, $sppgId, $konteks, $isi);
    }
}
