<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-06a: kepemilikan SPPG oleh investor (satu investor -> banyak SPPG). Aditif: tidak mengubah
 * data lama. Investor selalu users.sppg_id = null; SPPG yang boleh diakses HANYA dari tabel ini.
 * Dikelola Admin lewat PUT /pengguna/{id}/kepemilikan-sppg (tercatat di audit_logs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investor_sppg', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('sppg_id')->constrained('sppgs')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'sppg_id']);
            $table->index('sppg_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investor_sppg');
    }
};
