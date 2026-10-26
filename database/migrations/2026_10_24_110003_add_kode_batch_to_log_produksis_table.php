<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PBI 10: kode batch otomatis (ketertelusuran keluhan -> batch) dan waktu mulai produksi.
 * Unik per SPPG; baris lama boleh null. Log terhapus lunak tetap menempati kode (dipulihkan, bukan dibuat ulang).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('log_produksis', function (Blueprint $table) {
            $table->string('kode_batch', 40)->nullable()->after('jadwal_produksi_id');
            $table->time('waktu_mulai')->nullable()->after('realisasi_porsi');
            $table->unique(['sppg_id', 'kode_batch']);
        });
    }

    public function down(): void
    {
        Schema::table('log_produksis', function (Blueprint $table) {
            $table->dropUnique(['sppg_id', 'kode_batch']);
            $table->dropColumn(['kode_batch', 'waktu_mulai']);
        });
    }
};
