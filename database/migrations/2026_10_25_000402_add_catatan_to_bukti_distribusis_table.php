<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan umum berita acara serah terima (selain catatan_selisih) dan acuan waktu tiba yang
 * dipakai deteksi keterlambatan (estimasi perjalanan atau jam rencana tiba). Aditif, nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bukti_distribusis', function (Blueprint $table) {
            $table->text('catatan')->nullable()->after('catatan_selisih');
            $table->dateTime('acuan_tiba')->nullable()->after('waktu_tiba');
        });
    }

    public function down(): void
    {
        Schema::table('bukti_distribusis', function (Blueprint $table) {
            $table->dropColumn(['catatan', 'acuan_tiba']);
        });
    }
};
