<?php

namespace App\Http\Requests\Pengguna;

use App\Models\User;

class UpdatePenggunaRequest extends PenggunaRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User && $this->user()?->can('update', $target) === true;
    }

    protected function creating(): bool
    {
        return false;
    }

    protected function target(): ?User
    {
        $target = $this->route('user');

        return $target instanceof User ? $target : null;
    }
}
