<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standar_gizis', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->foreignId('sppg_id')->constrained('sppgs')->restrictOnDelete();
            $table->string('kelompok_sasaran', 20);
            $table->decimal('energi_min', 8, 2)->default(0);
            $table->decimal('energi_max', 8, 2)->default(0);
            $table->decimal('protein_min', 8, 2)->default(0);
            $table->decimal('protein_max', 8, 2)->default(0);
            $table->decimal('lemak_min', 8, 2)->default(0);
            $table->decimal('lemak_max', 8, 2)->default(0);
            $table->decimal('karbohidrat_min', 8, 2)->default(0);
            $table->decimal('karbohidrat_max', 8, 2)->default(0);
            $table->decimal('serat_min', 8, 2)->default(0);
            $table->decimal('serat_max', 8, 2)->default(0);
            $table->unique(['sppg_id', 'kelompok_sasaran']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('standar_gizis');
    }
};
