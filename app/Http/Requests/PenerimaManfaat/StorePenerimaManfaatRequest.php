<?php

namespace App\Http\Requests\PenerimaManfaat;

use App\Models\PenerimaManfaat;

class StorePenerimaManfaatRequest extends PenerimaManfaatRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', PenerimaManfaat::class) === true;
    }

    protected function creating(): bool
    {
        return true;
    }
}
