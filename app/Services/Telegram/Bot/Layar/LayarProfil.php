<?php

namespace App\Services\Telegram\Bot\Layar;

use App\Models\Sppg;
use App\Models\User;
use App\Services\Telegram\Bot\BotLayar;

/**
 * Profil Saya (layar `pf`, hak R* self-only): nama, peran, unit, dan nama SPPG milik pengguna sendiri. Tanpa
 * telepon, username, atau akun Telegram. Data diambil dari pengguna yang sedang login (bukan dari token).
 */
final class LayarProfil extends LayarModulDasar
{
    public function id(): string
    {
        return 'pf';
    }

    public function modul(): ?string
    {
        return 'pengguna';
    }

    public function hak(): string
    {
        return self::HAK_BACA_MILIK_SENDIRI;
    }

    protected function judul(): string
    {
        return 'Profil Saya';
    }

    public function render(User $user, ?int $sppgId, KonteksLayar $konteks): BotLayar
    {
        $sppg = $user->sppg_id === null ? null : Sppg::query()->withoutGlobalScopes()->whereKey($user->sppg_id)->value('nama');
        $isi = [
            '- '.self::e('Nama: '.FormatLayar::nama($user->name)),
            '- '.self::e('Peran: '.$user->role->label()),
        ];

        if ($user->unit !== null) {
            $isi[] = '- '.self::e('Unit: '.$user->unit->label());
        }

        $isi[] = '- '.self::e('SPPG: '.FormatLayar::nama($sppg));

        return $this->susun($user, null, $konteks, $isi);
    }
}
