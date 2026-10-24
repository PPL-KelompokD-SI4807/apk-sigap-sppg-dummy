<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bukti_distribusis', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->foreignId('sppg_id')->constrained('sppgs')->restrictOnDelete();
            $table->foreignId('jadwal_distribusi_id')->unique()->constrained('jadwal_distribusis')->restrictOnDelete();
            $table->foreignId('dicatat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('waktu_berangkat')->nullable();
            $table->dateTime('waktu_tiba');
            $table->dateTime('waktu_ambil_foto')->nullable();
            $table->unsignedInteger('porsi_diterima');
            $table->string('nama_pic');
            $table->string('no_berita_acara', 50)->nullable();
            $table->string('berita_acara_path')->nullable();
            $table->string('foto_path')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('catatan_selisih')->nullable();
            $table->boolean('terlambat')->default(false)->index();
            $table->unsignedInteger('menit_keterlambatan')->nullable();
            $table->index(['sppg_id', 'waktu_tiba']);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bukti_distribusis');
    }
};
