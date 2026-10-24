<?php

namespace App\Policies;

/**
 * Jadwal distribusi (modul 9). Relawan unit distribusi CRU atas jadwal distribusi; update hanya
 * jadwal buatannya sendiri ($ownerColumn = dibuat_oleh) pada hari yang sama.
 */
class JadwalDistribusiPolicy extends BaseSppgPolicy
{
    protected string $module = 'jadwal';

    protected ?string $jenis = 'distribusi';

    protected ?string $ownerColumn = 'dibuat_oleh';
}
