<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-03: tantangan token sekali pakai 6 digit (dikirim bot ke users.telegram_id dari basis data).
 *
 * - id ULID (tidak berurutan); terikat ke user + tujuan + perangkat (device_hash). Pemakaian hanya
 *   dari token pra-login milik user yang sama dan device_id yang sama (Mini App boleh dibuka ulang).
 * - kode_hash = HMAC-SHA256(app key, id|kode); kode mentah tidak pernah disimpan.
 * - payload terenkripsi (hash password baru, username, no telepon) dan dikosongkan setelah selesai.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tantangan_tokens', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('tujuan', 30);
            $table->char('device_hash', 64);
            $table->char('kode_hash', 64);
            $table->unsignedTinyInteger('percobaan')->default(0);
            $table->unsignedTinyInteger('kirim_ulang')->default(0);
            $table->timestamp('terkirim_pada');
            $table->timestamp('kedaluwarsa_pada');
            $table->timestamp('dipakai_pada')->nullable();
            $table->timestamp('hangus_pada')->nullable();
            $table->text('payload')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tantangan_tokens');
    }
};
