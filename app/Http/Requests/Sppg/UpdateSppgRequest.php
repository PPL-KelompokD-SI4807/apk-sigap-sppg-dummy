<?php

namespace App\Http\Requests\Sppg;

use App\Models\Sppg;

class UpdateSppgRequest extends SppgRequest
{
    public function authorize(): bool
    {
        $sppg = $this->route('sppg');

        return $sppg instanceof Sppg && $this->user()?->can('update', $sppg) === true;
    }

    protected function creating(): bool
    {
        return false;
    }
}
