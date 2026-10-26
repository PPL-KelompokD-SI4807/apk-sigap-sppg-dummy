<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jenis lampiran dalam satu record induk (mis. bukti distribusi: foto / berita_acara;
 * pengeluaran: nota). Aditif, nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lampirans', function (Blueprint $table) {
            $table->string('kategori', 30)->nullable()->after('uploaded_by');
            $table->index(['sppg_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('lampirans', function (Blueprint $table) {
            $table->dropIndex(['sppg_id', 'created_at']);
            $table->dropColumn('kategori');
        });
    }
};
