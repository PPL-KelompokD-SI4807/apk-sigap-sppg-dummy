<?php

namespace Tests\Fixtures;

use App\Policies\BaseSppgPolicy;

class BahanBakuLikePolicy extends BaseSppgPolicy
{
    protected string $module = 'bahan-baku';
}
