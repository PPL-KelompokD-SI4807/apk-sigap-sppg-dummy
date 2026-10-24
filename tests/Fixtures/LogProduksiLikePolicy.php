<?php

namespace Tests\Fixtures;

use App\Policies\BaseSppgPolicy;

class LogProduksiLikePolicy extends BaseSppgPolicy
{
    protected string $module = 'log-produksi';
}
