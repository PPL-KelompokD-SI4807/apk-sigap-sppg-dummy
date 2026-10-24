<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengguna SiGAP SPPG. Login hanya melalui Telegram (initData HMAC), sehingga
 * kolom email/password/remember_token dan tabel password_reset_tokens bawaan dihapus.
 * FK sppg_id -> sppgs ditambahkan oleh migrasi 2026_10_24_100028 setelah tabel sppgs ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('sppg_id')->nullable()->index();
            $table->unsignedBigInteger('telegram_id')->nullable()->unique();
            $table->string('telegram_username', 64)->nullable();
            $table->string('name');
            $table->string('role', 30)->index();
            $table->string('unit', 20)->nullable();
            $table->string('phone', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Dipakai rute web (SESSION_DRIVER=database). API memakai token Bearer tanpa sesi.
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
