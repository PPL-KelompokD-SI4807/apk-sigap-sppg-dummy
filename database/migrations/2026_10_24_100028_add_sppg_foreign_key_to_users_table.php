<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menambahkan FK users.sppg_id -> sppgs.id setelah tabel sppgs ada.
 * Kolom users.sppg_id dibuat oleh migrasi fondasi auth (nullable, tanpa FK).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'sppg_id')) {
            throw new RuntimeException('Kolom users.sppg_id belum ada; jalankan migrasi fondasi auth lebih dulu.');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('sppg_id', 'users_sppg_id_foreign')
                ->references('id')->on('sppgs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign('users_sppg_id_foreign');
        });
    }
};
