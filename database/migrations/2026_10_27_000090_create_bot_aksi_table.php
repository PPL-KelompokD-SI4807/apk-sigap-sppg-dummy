<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-02: status tombol aksi Bot Telegram (callback_data = kode acak ber-HMAC; semua makna aksi ada di baris ini).
 * Migrasi aditif (tabel baru, tidak mengubah/menghapus data). TANPA NIK, telepon, nama, atau isi catatan;
 * hanya penunjuk (jenis, subjek, versi yang dilihat pengguna) dan status sekali pakai. Dibersihkan terjadwal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_aksi', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->char('kode', 11)->unique();
            $table->char('grup', 11)->nullable()->index();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->bigInteger('telegram_id');
            $table->bigInteger('chat_id');
            $table->foreignId('sppg_id')->nullable()->constrained('sppgs')->nullOnDelete();
            $table->string('jenis', 24);
            $table->string('subjek_tipe', 20)->nullable();
            $table->unsignedBigInteger('subjek_id')->nullable();
            $table->unsignedInteger('subjek_versi')->nullable();
            $table->string('status', 20)->default('aktif');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('catatan_expires_at')->nullable();
            $table->unsignedBigInteger('prompt_message_id')->nullable();
            $table->string('hasil', 40)->nullable();
            $table->timestamps();
            $table->index(['telegram_id', 'status']);
            $table->index('expires_at');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_aksi');
    }
};
