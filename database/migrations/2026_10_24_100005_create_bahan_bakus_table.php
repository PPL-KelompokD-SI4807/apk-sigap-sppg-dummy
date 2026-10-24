<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bahan_bakus', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->foreignId('sppg_id')->constrained('sppgs')->restrictOnDelete();
            $table->foreignId('pemasok_id')->nullable()->constrained('pemasoks')->nullOnDelete();
            $table->string('kode', 30)->nullable();
            $table->string('nama');
            $table->string('kategori', 30)->index();
            $table->string('satuan', 20);
            $table->decimal('stok', 12, 3)->default(0);
            $table->decimal('stok_minimum', 12, 3)->default(0);
            $table->date('kedaluwarsa_terdekat')->nullable();
            $table->index(['sppg_id', 'nama']);
            $table->index(['sppg_id', 'kedaluwarsa_terdekat']);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bahan_bakus');
    }
};
