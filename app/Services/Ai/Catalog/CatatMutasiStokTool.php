<?php

namespace App\Services\Ai\Catalog;

/**
 * `catat_mutasi_stok`: DRAF mutasi stok (masuk/keluar/penyesuaian) pada satu bahan baku. Ditulis sebagai
 * tool ubah (aksi U pada bahan baku; target_id = bahan baku dari cari_bahan_baku) dengan penanda
 * `mutasi_stok` agar draf divalidasi ulang dengan StoreMutasiStokRequest. Saldo baru berubah saat pengguna
 * menyimpan di Mini App (POST /bahan-baku/{id}/mutasi + ai_draft_id).
 */
final class CatatMutasiStokTool extends CatalogWriteTool
{
    public function __construct(ResourceSpec $spec)
    {
        parent::__construct($spec, 'U');

        $this->name = 'catat_mutasi_stok';
        $this->description = 'Siapkan DRAF mutasi stok bahan baku (masuk, keluar, atau penyesuaian). target_id = ID bahan baku dari tool cari_bahan_baku. Stok BELUM berubah sampai pengguna menyimpan di Mini App.';
    }
}
