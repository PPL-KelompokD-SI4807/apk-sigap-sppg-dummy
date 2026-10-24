<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pemasoks', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->foreignId('sppg_id')->constrained('sppgs')->restrictOnDelete();
            $table->string('nama');
            $table->string('jenis', 20)->index();
            $table->string('kontak', 30)->nullable();
            $table->string('alamat')->nullable();
            $table->string('jenis_bahan')->nullable();
            $table->boolean('is_active')->default(true);
            $table->index(['sppg_id', 'nama']);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pemasoks');
    }
};
