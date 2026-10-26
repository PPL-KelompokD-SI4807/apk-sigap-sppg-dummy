<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sppgs', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->string('kode', 30)->unique();
            $table->string('nama');
            $table->string('alamat');
            $table->string('mitra_yayasan')->nullable();
            $table->string('telepon', 30)->nullable();
            $table->unsignedInteger('kapasitas_porsi_harian')->default(0);
            $table->string('status_operasional', 20)->default('aktif')->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sppgs');
    }
};
