<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pencairan_danas', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->foreignId('sppg_id')->constrained('sppgs')->restrictOnDelete();
            $table->foreignId('dicatat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->string('periode', 20);
            $table->string('sumber_dana');
            $table->decimal('nominal', 15, 2);
            $table->date('tanggal_cair');
            $table->string('no_referensi', 50)->nullable();
            $table->string('bukti_path')->nullable();
            $table->text('catatan')->nullable();
            $table->index(['sppg_id', 'periode']);
            $table->index(['sppg_id', 'tanggal_cair']);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pencairan_danas');
    }
};
