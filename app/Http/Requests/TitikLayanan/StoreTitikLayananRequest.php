<?php

namespace App\Http\Requests\TitikLayanan;

use App\Models\TitikLayanan;

class StoreTitikLayananRequest extends TitikLayananRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', TitikLayanan::class) === true && $this->hasSppgContext();
    }

    protected function creating(): bool
    {
        return true;
    }
}
