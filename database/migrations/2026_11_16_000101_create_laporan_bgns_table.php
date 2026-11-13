<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R07-2: pencatat laporan Lampiran 30 (BGN) per SPPG + periode, sekaligus penomor `xx/LPA/x/tahun`.
 * Tabel baru (tanpa mengubah data lama). Cetak ulang periode yang sama = nomor yang sama: unik per
 * (sppg, jenis, periode). Nomor urut unik per (sppg, tahun). Tanpa soft delete: nomor yang pernah terbit
 * tidak boleh dipakai ulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laporan_bgns', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->foreignId('sppg_id')->constrained('sppgs')->restrictOnDelete();
            $table->string('jenis', 20);
            $table->date('periode_awal');
            $table->date('periode_akhir');
            $table->unsignedSmallInteger('tahun');
            $table->unsignedInteger('nomor_urut')->nullable();
            $table->string('nomor', 40)->nullable();
            $table->foreignId('dibuat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['sppg_id', 'jenis', 'periode_awal', 'periode_akhir'], 'laporan_bgns_periode_unik');
            $table->unique(['sppg_id', 'tahun', 'nomor_urut'], 'laporan_bgns_nomor_unik');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laporan_bgns');
    }
};
