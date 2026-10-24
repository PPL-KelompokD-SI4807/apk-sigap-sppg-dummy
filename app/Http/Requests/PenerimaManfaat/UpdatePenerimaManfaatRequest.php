<?php

namespace App\Http\Requests\PenerimaManfaat;

use App\Models\PenerimaManfaat;

class UpdatePenerimaManfaatRequest extends PenerimaManfaatRequest
{
    public function authorize(): bool
    {
        $record = $this->route('penerimaManfaat');

        return $record instanceof PenerimaManfaat && $this->user()?->can('update', $record) === true;
    }

    protected function creating(): bool
    {
        return false;
    }
}
