<?php

namespace App\Http\Requests\Armada;

use App\Models\Armada;

class StoreArmadaRequest extends ArmadaRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Armada::class) === true && $this->hasSppgContext();
    }

    protected function creating(): bool
    {
        return true;
    }
}
