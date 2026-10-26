<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menus', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->foreignId('sppg_id')->constrained('sppgs')->restrictOnDelete();
            $table->string('nama_menu');
            $table->string('kelompok_sasaran', 20)->index();
            $table->date('tanggal_sajian')->nullable();
            $table->string('siklus', 50)->nullable();
            $table->decimal('energi_kkal', 8, 2)->default(0);
            $table->decimal('protein_g', 8, 2)->default(0);
            $table->decimal('lemak_g', 8, 2)->default(0);
            $table->decimal('karbohidrat_g', 8, 2)->default(0);
            $table->decimal('serat_g', 8, 2)->default(0);
            $table->text('catatan')->nullable();
            $table->index(['sppg_id', 'tanggal_sajian']);
            $table->index(['sppg_id', 'nama_menu']);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menus');
    }
};
