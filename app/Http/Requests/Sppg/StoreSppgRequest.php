<?php

namespace App\Http\Requests\Sppg;

use App\Models\Sppg;

class StoreSppgRequest extends SppgRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Sppg::class) === true;
    }

    protected function creating(): bool
    {
        return true;
    }
}
