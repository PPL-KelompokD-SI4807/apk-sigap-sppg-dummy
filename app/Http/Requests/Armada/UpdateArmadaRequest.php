<?php

namespace App\Http\Requests\Armada;

use App\Models\Armada;

class UpdateArmadaRequest extends ArmadaRequest
{
    public function authorize(): bool
    {
        $armada = $this->route('armada');

        return $armada instanceof Armada && $this->user()?->can('update', $armada) === true;
    }

    protected function creating(): bool
    {
        return false;
    }
}
