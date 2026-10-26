<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jadwal_distribusis', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->foreignId('sppg_id')->constrained('sppgs')->restrictOnDelete();
            $table->foreignId('jadwal_produksi_id')->constrained('jadwal_produksis')->restrictOnDelete();
            $table->foreignId('titik_layanan_id')->constrained('titik_layanans')->restrictOnDelete();
            $table->foreignId('armada_id')->nullable()->constrained('armadas')->nullOnDelete();
            $table->foreignId('pengemudi_id')->nullable()->constrained('pengemudis')->nullOnDelete();
            $table->foreignId('petugas_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('tanggal_distribusi');
            $table->time('jam_berangkat')->nullable();
            $table->time('jam_rencana_tiba')->nullable();
            $table->unsignedInteger('jumlah_porsi');
            $table->string('status', 20)->default('menunggu');
            $table->text('catatan')->nullable();
            $table->index(['sppg_id', 'tanggal_distribusi']);
            $table->index(['sppg_id', 'status']);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jadwal_distribusis');
    }
};
