<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda atomik "bukti distribusi belum masuk" (sigap:cek-bukti-distribusi): event
 * BuktiDistribusiBelumMasuk dipancarkan sekali per jadwal. Aditif, nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jadwal_distribusis', function (Blueprint $table) {
            $table->timestamp('bukti_belum_masuk_pada')->nullable()->after('status');
            $table->index(['status', 'bukti_belum_masuk_pada', 'tanggal_distribusi'], 'jadwal_distribusis_pemindai_bukti_index');
        });
    }

    public function down(): void
    {
        Schema::table('jadwal_distribusis', function (Blueprint $table) {
            $table->dropIndex('jadwal_distribusis_pemindai_bukti_index');
            $table->dropColumn('bukti_belum_masuk_pada');
        });
    }
};
