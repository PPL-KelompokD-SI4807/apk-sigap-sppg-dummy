<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback_keluhans', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->foreignId('sppg_id')->constrained('sppgs')->restrictOnDelete();
            $table->foreignId('titik_layanan_id')->nullable()->constrained('titik_layanans')->nullOnDelete();
            $table->foreignId('jadwal_produksi_id')->nullable()->constrained('jadwal_produksis')->nullOnDelete();
            $table->foreignId('dicatat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ditangani_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sumber', 20);
            $table->string('kategori', 30)->index();
            $table->string('prioritas', 20)->index();
            $table->string('status', 20)->default('baru')->index();
            $table->text('deskripsi');
            $table->string('foto_path')->nullable();
            $table->text('tindak_lanjut')->nullable();
            $table->dateTime('dieskalasi_pada')->nullable();
            $table->dateTime('selesai_pada')->nullable();
            $table->index(['sppg_id', 'created_at']);
            $table->index(['sppg_id', 'status', 'prioritas']);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_keluhans');
    }
};
