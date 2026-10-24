<?php

namespace App\Http\Requests\MenuGizi;

class UpdateMenuRequest extends StoreMenuRequest
{
    protected function sppgId(): ?int
    {
        return $this->menuRoute()?->sppg_id ?? parent::sppgId();
    }
}
