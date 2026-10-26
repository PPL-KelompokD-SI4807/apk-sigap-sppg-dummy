<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-06b-1: riwayat harga bahan per pemasok (termasuk koperasi). Append-only: koreksi = entri baru.
 * `harga` per satuan bahan; `gram_per_satuan` hanya untuk satuan non-massa/volume (butir, ikat, ...).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('harga_pemasoks', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->foreignId('sppg_id')->constrained('sppgs')->restrictOnDelete();
            $table->foreignId('pemasok_id')->constrained('pemasoks')->restrictOnDelete();
            $table->foreignId('bahan_baku_id')->constrained('bahan_bakus')->restrictOnDelete();
            $table->decimal('harga', 14, 2);
            $table->decimal('gram_per_satuan', 12, 3)->nullable();
            $table->date('berlaku_mulai');
            $table->string('catatan', 255)->nullable();
            $table->foreignId('dicatat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['sppg_id', 'bahan_baku_id', 'berlaku_mulai']);
            $table->index(['pemasok_id', 'bahan_baku_id', 'berlaku_mulai']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('harga_pemasoks');
    }
};
