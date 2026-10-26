<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PBI 8: standar gizi dikelola ahli_gizi (CRUD) dan penghapusannya wajib hapus lunak + audit.
 * Unik (sppg_id, kelompok_sasaran) tetap: standar terhapus dipulihkan (restore) saat dibuat ulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('standar_gizis', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('standar_gizis', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
