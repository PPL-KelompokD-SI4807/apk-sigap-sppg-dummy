<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-06b-2: pengajuan persetujuan pengeluaran ke investor (snapshot nilai saat diajukan).
 * Imutabel (dijaga model PersetujuanPengeluaran): tanpa soft delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('persetujuan_pengeluarans', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->foreignId('sppg_id')->constrained('sppgs')->restrictOnDelete();
            $table->foreignId('pengeluaran_id')->constrained('pengeluarans')->restrictOnDelete();
            $table->unsignedInteger('nomor');
            $table->unsignedInteger('pengeluaran_versi');
            $table->string('status', 20)->default('diajukan');
            $table->string('kategori', 30);
            $table->decimal('nominal', 15, 2);
            $table->date('tanggal');
            $table->foreignId('pemasok_id')->nullable()->constrained('pemasoks')->nullOnDelete();
            $table->decimal('ambang_persetujuan', 15, 2)->default(0);
            $table->foreignId('diajukan_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('diajukan_pada');
            $table->foreignId('diputuskan_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('diputuskan_pada')->nullable();
            $table->string('catatan_keputusan', 500)->nullable();
            $table->timestamps();
            $table->unique(['pengeluaran_id', 'nomor']);
            $table->index(['sppg_id', 'status']);
            $table->index(['pengeluaran_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('persetujuan_pengeluarans');
    }
};
