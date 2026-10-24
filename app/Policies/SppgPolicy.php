<?php

namespace App\Policies;

use Illuminate\Database\Eloquent\Model;

/**
 * Modul Data SPPG (Tabel 5): Admin CRUD, Kepala SPPG RU, lainnya R. Tabel sppgs tidak memiliki
 * kolom sppg_id: pemilik SPPG adalah baris itu sendiri (id), sehingga non-admin hanya dapat
 * mengakses SPPG tempatnya bertugas.
 */
class SppgPolicy extends BaseSppgPolicy
{
    protected string $module = 'sppg';

    protected function sppgIdOf(Model $model): ?int
    {
        $id = $model->getKey();

        return $id === null ? null : (int) $id;
    }
}
