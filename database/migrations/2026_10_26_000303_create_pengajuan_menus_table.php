<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-06b-1: pengajuan persetujuan menu ke investor. Snapshot harga/biaya dibekukan saat diajukan.
 * Imutabel (dijaga model PengajuanMenu): tanpa soft delete, hanya transisi diajukan -> status akhir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengajuan_menus', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->foreignId('sppg_id')->constrained('sppgs')->restrictOnDelete();
            $table->foreignId('menu_id')->constrained('menus')->restrictOnDelete();
            $table->unsignedInteger('nomor');
            $table->unsignedInteger('menu_versi');
            $table->string('status', 20)->default('diajukan');
            $table->json('rincian');
            $table->decimal('biaya_per_porsi', 14, 2);
            $table->unsignedInteger('jumlah_porsi');
            $table->string('sumber_porsi', 40);
            $table->string('keterangan_asumsi', 255);
            $table->decimal('total_biaya', 16, 2);
            $table->text('catatan_pengaju')->nullable();
            $table->foreignId('diajukan_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('diajukan_pada');
            $table->foreignId('diputuskan_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('diputuskan_pada')->nullable();
            $table->text('catatan_keputusan')->nullable();
            $table->timestamps();
            $table->unique(['menu_id', 'nomor']);
            $table->index(['sppg_id', 'status']);
            $table->index(['menu_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengajuan_menus');
    }
};
