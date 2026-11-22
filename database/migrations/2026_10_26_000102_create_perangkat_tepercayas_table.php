<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-03: perangkat tepercaya (lolos token 6 digit). device_id klien hanya disimpan sebagai
 * HMAC-SHA256 dengan app key. dicabut_at terisi = kepercayaan dicabut (logout semua, password
 * diubah/di-reset, hak akses berubah); baris tidak dihapus agar riwayat tetap ada.
 * personal_access_tokens.perangkat_tepercaya_id menautkan sesi login penuh ke perangkatnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perangkat_tepercayas', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('device_hash', 64);
            $table->string('label', 120)->nullable();
            $table->timestamp('diverifikasi_pada');
            $table->timestamp('dicabut_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'device_hash']);
        });

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('perangkat_tepercaya_id')->nullable()->index()->after('abilities');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropIndex(['perangkat_tepercaya_id']);
            $table->dropColumn('perangkat_tepercaya_id');
        });

        Schema::dropIfExists('perangkat_tepercayas');
    }
};
