<?php

namespace Tests\Fixtures;

use App\Policies\BaseSppgPolicy;

class JadwalDistribusiLikePolicy extends BaseSppgPolicy
{
    protected string $module = 'jadwal';

    protected ?string $jenis = 'distribusi';
}
