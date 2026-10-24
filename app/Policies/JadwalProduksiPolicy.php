<?php

namespace App\Policies;

/**
 * Jadwal produksi (modul 9). Relawan unit dapur CRU atas jadwal produksi; hanya jadwal buatannya
 * sendiri ($ownerColumn = dibuat_oleh) pada hari yang sama untuk update.
 */
class JadwalProduksiPolicy extends BaseSppgPolicy
{
    protected string $module = 'jadwal';

    protected ?string $jenis = 'produksi';

    protected ?string $ownerColumn = 'dibuat_oleh';
}
