<?php

namespace App\Policies;

/** Standar gizi per kelompok sasaran termasuk modul 8 (menu-gizi). */
class StandarGiziPolicy extends BaseSppgPolicy
{
    protected string $module = 'menu-gizi';
}
