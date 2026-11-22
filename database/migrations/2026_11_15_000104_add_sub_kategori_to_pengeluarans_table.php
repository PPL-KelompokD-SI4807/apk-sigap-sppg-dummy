<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** R07-1: 17 baris baku Biaya Operasional Lampiran 30b-B. ADD COLUMN nullable, tanpa backfill (Q-5 dipetakan saat laporan). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pengeluarans', function (Blueprint $table) {
            $table->string('sub_kategori', 30)->nullable()->after('kategori');
        });
    }

    public function down(): void
    {
        Schema::table('pengeluarans', function (Blueprint $table) {
            $table->dropColumn('sub_kategori');
        });
    }
};
