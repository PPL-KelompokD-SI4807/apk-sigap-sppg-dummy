<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mutasi_stoks', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->foreignId('sppg_id')->constrained('sppgs')->restrictOnDelete();
            $table->foreignId('bahan_baku_id')->constrained('bahan_bakus')->restrictOnDelete();
            $table->foreignId('jadwal_produksi_id')->nullable()->constrained('jadwal_produksis')->nullOnDelete();
            $table->foreignId('pemasok_id')->nullable()->constrained('pemasoks')->nullOnDelete();
            $table->foreignId('dicatat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->string('jenis', 20);
            // masuk/keluar bernilai positif; penyesuaian boleh negatif (selisih).
            $table->decimal('jumlah', 12, 3);
            $table->decimal('harga_satuan', 15, 2)->nullable();
            $table->date('tanggal_mutasi');
            $table->date('tanggal_kedaluwarsa')->nullable();
            $table->text('catatan')->nullable();
            $table->index(['sppg_id', 'tanggal_mutasi']);
            $table->index(['bahan_baku_id', 'jenis']);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mutasi_stoks');
    }
};
