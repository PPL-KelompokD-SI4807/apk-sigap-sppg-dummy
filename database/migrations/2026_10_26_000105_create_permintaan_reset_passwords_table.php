<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-03: permintaan reset password yang disetujui Admin. Satu permintaan aktif (menunggu/disetujui,
 * belum kedaluwarsa) per akun, berlaku 24 jam. Tidak menyimpan password, token, atau no telepon.
 * id ULID (tidak berurutan) karena dipakai di URL tindakan Admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permintaan_reset_passwords', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('sppg_id')->nullable()->constrained('sppgs')->nullOnDelete();
            $table->string('status', 20)->index();
            $table->timestamp('kedaluwarsa_pada');
            $table->foreignId('diputuskan_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('diputuskan_pada')->nullable();
            $table->timestamp('selesai_pada')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index(['sppg_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permintaan_reset_passwords');
    }
};
