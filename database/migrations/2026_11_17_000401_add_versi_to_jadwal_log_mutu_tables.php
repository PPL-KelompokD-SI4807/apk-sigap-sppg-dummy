<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #127: penanda versi (kunci optimistik, 409) untuk jadwal produksi, jadwal distribusi, log produksi,
 * dan pemeriksaan mutu. Aditif: kolom baru dengan default 1; baris lama menjadi versi 1; tanpa mengubah data.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tabel = ['jadwal_produksis', 'jadwal_distribusis', 'log_produksis', 'pemeriksaan_mutus'];

    public function up(): void
    {
        foreach ($this->tabel as $nama) {
            Schema::table($nama, function (Blueprint $table) {
                $table->unsignedInteger('versi')->default(1);
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tabel as $nama) {
            Schema::table($nama, function (Blueprint $table) {
                $table->dropColumn('versi');
            });
        }
    }
};
