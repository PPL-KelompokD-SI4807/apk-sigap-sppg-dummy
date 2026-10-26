<?php

namespace App\Models\Scopes;

/**
 * Opsional: model yang kolom isolasinya bukan `sppg_id` (mis. tabel sppgs memakai `id`).
 */
interface HasSppgColumn
{
    public function sppgColumn(): string;
}
