<?php

namespace App\Http\Requests\Armada;

use App\Models\Pengemudi;

class UpdatePengemudiRequest extends PengemudiRequest
{
    public function authorize(): bool
    {
        $pengemudi = $this->route('pengemudi');

        return $pengemudi instanceof Pengemudi && $this->user()?->can('update', $pengemudi) === true;
    }

    protected function creating(): bool
    {
        return false;
    }
}
