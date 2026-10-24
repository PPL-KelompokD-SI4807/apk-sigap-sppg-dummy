<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('penerima_manfaats', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->foreignId('sppg_id')->constrained('sppgs')->restrictOnDelete();
            $table->foreignId('titik_layanan_id')->constrained('titik_layanans')->restrictOnDelete();
            $table->string('nama');
            $table->string('kelompok_sasaran', 20)->index();
            $table->string('kelas_keterangan')->nullable();
            $table->text('catatan_alergi')->nullable();
            $table->boolean('is_active')->default(true);
            $table->index(['sppg_id', 'nama']);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penerima_manfaats');
    }
};
