<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pemeriksaan_mutus', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->foreignId('sppg_id')->constrained('sppgs')->restrictOnDelete();
            $table->foreignId('jadwal_produksi_id')->constrained('jadwal_produksis')->restrictOnDelete();
            $table->foreignId('pemeriksa_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('waktu_pemeriksaan');
            $table->decimal('suhu_makanan_c', 4, 1)->nullable();
            $table->boolean('kebersihan_dapur')->default(true);
            $table->boolean('kebersihan_petugas')->default(true);
            $table->boolean('organoleptik_baik')->default(true);
            $table->text('catatan_organoleptik')->nullable();
            $table->boolean('sampel_disimpan')->default(false);
            $table->string('hasil', 20)->index();
            $table->text('tindakan_korektif')->nullable();
            $table->string('foto_path')->nullable();
            $table->index(['sppg_id', 'waktu_pemeriksaan']);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pemeriksaan_mutus');
    }
};
