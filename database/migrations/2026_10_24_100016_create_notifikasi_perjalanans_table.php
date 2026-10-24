<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifikasi_perjalanans', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->foreignId('sppg_id')->constrained('sppgs')->restrictOnDelete();
            $table->foreignId('jadwal_distribusi_id')->constrained('jadwal_distribusis')->restrictOnDelete();
            $table->foreignId('titik_layanan_id')->constrained('titik_layanans')->restrictOnDelete();
            $table->foreignId('dipicu_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('penerima_chat_id')->nullable();
            $table->boolean('dialihkan_ke_kepala')->default(false);
            $table->dateTime('waktu_kirim')->nullable();
            $table->dateTime('estimasi_tiba');
            $table->string('status_kirim', 20)->default('menunggu')->index();
            $table->text('pesan')->nullable();
            $table->text('error')->nullable();
            $table->index(['sppg_id', 'created_at']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifikasi_perjalanans');
    }
};
