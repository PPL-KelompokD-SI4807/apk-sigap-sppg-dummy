<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda idempoten peringatan serapan anggaran (FR-10: 80%/100%). Satu baris per
 * (sppg, periode, ambang) = event sudah dipancarkan; unik mencegah notifikasi ganda saat
 * pengeluaran diedit atau dikirim bersamaan. Tabel log (tanpa soft delete).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('peringatan_anggarans', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->foreignId('sppg_id')->constrained('sppgs')->restrictOnDelete();
            $table->string('periode', 20);
            $table->string('ambang', 20);
            $table->decimal('anggaran', 15, 2);
            $table->decimal('terpakai', 15, 2);
            $table->decimal('persen', 8, 2);
            $table->timestamps();
            $table->unique(['sppg_id', 'periode', 'ambang']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('peringatan_anggarans');
    }
};
