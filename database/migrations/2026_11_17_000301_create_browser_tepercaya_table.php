<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R04-3 (ADR 2026-11-09 Keputusan 6, A2): browser tepercaya web ("Ingat browser ini 30 hari").
 *
 * Aditif saja: tabel baru + satu kolom nullable di personal_access_tokens. Tidak ada data yang diubah/dihapus.
 * - selektor        : bagian publik cookie `__Host-sigap_tb` (128-bit acak, base64url), unik, untuk pencarian.
 * - validator_hash  : SHA-256 dari validator 256-bit (validator mentah tidak pernah disimpan/dicatat).
 * - berlaku_sampai  : diverifikasi_pada + 30 hari, TETAP (tidak bergeser saat dipakai).
 * - dicabut_at/alasan_cabut : baris tidak dihapus saat dicabut (riwayat).
 * personal_access_tokens.browser_tepercaya_id menautkan sesi web yang terbit lewat browser tepercaya (pencabutan
 * berantai, pengecualian idle 12 jam, sesi tidak melewati berlaku_sampai browser).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('browser_tepercaya', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('selektor', 22)->unique();
            $table->char('validator_hash', 64);
            $table->string('label', 120)->nullable();
            $table->timestamp('diverifikasi_pada');
            $table->timestamp('berlaku_sampai');
            $table->timestamp('terakhir_dipakai_at')->nullable();
            $table->timestamp('dicabut_at')->nullable();
            $table->string('alasan_cabut', 40)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'dicabut_at', 'berlaku_sampai']);
        });

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('browser_tepercaya_id')->nullable()->index()->after('perangkat_tepercaya_id');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropIndex(['browser_tepercaya_id']);
            $table->dropColumn('browser_tepercaya_id');
        });

        Schema::dropIfExists('browser_tepercaya');
    }
};
