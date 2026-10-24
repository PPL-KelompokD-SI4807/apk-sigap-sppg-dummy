<?php

namespace App\Http\Requests\Pengguna;

use App\Models\User;

class StorePenggunaRequest extends PenggunaRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) === true;
    }

    protected function creating(): bool
    {
        return true;
    }
}
