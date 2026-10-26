<?php

namespace App\Http\Requests\Pemasok;

use App\Models\Pemasok;

class StorePemasokRequest extends PemasokRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Pemasok::class) === true && $this->hasSppgContext();
    }

    protected function creating(): bool
    {
        return true;
    }
}
