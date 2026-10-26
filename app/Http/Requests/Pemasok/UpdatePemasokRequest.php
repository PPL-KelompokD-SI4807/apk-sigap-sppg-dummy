<?php

namespace App\Http\Requests\Pemasok;

use App\Models\Pemasok;

class UpdatePemasokRequest extends PemasokRequest
{
    public function authorize(): bool
    {
        $pemasok = $this->route('pemasok');

        return $pemasok instanceof Pemasok && $this->user()?->can('update', $pemasok) === true;
    }

    protected function creating(): bool
    {
        return false;
    }
}
