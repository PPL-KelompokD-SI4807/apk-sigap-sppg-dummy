<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('titik_layanans', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->foreignId('sppg_id')->constrained('sppgs')->restrictOnDelete();
            $table->string('nama');
            $table->string('jenis', 20)->index();
            $table->string('alamat');
            $table->decimal('jarak_km', 6, 2)->default(0);
            $table->unsignedInteger('jumlah_sasaran')->default(0);
            $table->string('pic_nama');
            $table->string('pic_telepon', 30)->nullable();
            $table->unsignedBigInteger('pic_telegram_chat_id')->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->index(['sppg_id', 'nama']);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('titik_layanans');
    }
};
