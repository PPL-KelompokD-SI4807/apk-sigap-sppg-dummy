<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda pengeluaran yang saat dicatat/diubah nominalnya melebihi sisa anggaran periode
 * (FR-09: dicatat sesuai dokumen sumber, ditandai, bukan ditolak). Aditif, default false.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pengeluarans', function (Blueprint $table) {
            $table->boolean('melebihi_anggaran')->default(false)->after('nominal');
        });
    }

    public function down(): void
    {
        Schema::table('pengeluarans', function (Blueprint $table) {
            $table->dropColumn('melebihi_anggaran');
        });
    }
};
