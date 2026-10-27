<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-06b-2: pembelian bahan dicatat staf gudang (foto nota via lampiran), diverifikasi akuntan.
 * Pembelian terverifikasi menjadi pengeluaran kategori bahan_baku (pengeluaran_id). Kolom
 * pengeluaran_id sengaja belum berkunci asing di sini; FK dipasang di migrasi pengeluarans
 * (menghindari referensi melingkar saat tabel dibuat).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pembelian_bahans', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->foreignId('sppg_id')->constrained('sppgs')->restrictOnDelete();
            $table->foreignId('bahan_baku_id')->constrained('bahan_bakus')->restrictOnDelete();
            $table->foreignId('pemasok_id')->constrained('pemasoks')->restrictOnDelete();
            $table->date('tanggal_beli');
            $table->decimal('jumlah', 12, 3);
            $table->string('satuan', 20);
            $table->decimal('harga_satuan', 14, 2);
            $table->decimal('total', 15, 2);
            $table->string('catatan', 500)->nullable();
            $table->string('nota_path')->nullable();
            $table->string('status', 20)->default('diajukan');
            $table->unsignedInteger('versi')->default(1);
            $table->foreignId('dicatat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('diverifikasi_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('diverifikasi_pada')->nullable();
            $table->string('alasan_penolakan', 500)->nullable();
            $table->unsignedBigInteger('pengeluaran_id')->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['sppg_id', 'status', 'tanggal_beli']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pembelian_bahans');
    }
};
