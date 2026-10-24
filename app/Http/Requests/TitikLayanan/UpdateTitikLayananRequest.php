<?php

namespace App\Http\Requests\TitikLayanan;

use App\Models\TitikLayanan;

class UpdateTitikLayananRequest extends TitikLayananRequest
{
    public function authorize(): bool
    {
        $titik = $this->route('titikLayanan');

        return $titik instanceof TitikLayanan && $this->user()?->can('update', $titik) === true;
    }

    protected function creating(): bool
    {
        return false;
    }
}
