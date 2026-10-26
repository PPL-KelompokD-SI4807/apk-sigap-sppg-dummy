<?php

namespace App\Http\Requests\Armada;

use App\Models\Pengemudi;

class StorePengemudiRequest extends PengemudiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Pengemudi::class) === true && $this->hasSppgContext();
    }

    protected function creating(): bool
    {
        return true;
    }
}
