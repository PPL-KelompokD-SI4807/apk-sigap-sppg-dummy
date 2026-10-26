<?php

namespace App\Policies;

/** Modul 6 Bahan Baku (+ mutasi stok): admin & staf_gudang CRUD; peran lain baca. */
class BahanBakuPolicy extends BaseSppgPolicy
{
    protected string $module = 'bahan-baku';
}
