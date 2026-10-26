<?php

namespace Tests\Fixtures;

use App\Policies\BaseSppgPolicy;

class JadwalProduksiLikePolicy extends BaseSppgPolicy
{
    protected string $module = 'jadwal';

    protected ?string $jenis = 'produksi';
}
