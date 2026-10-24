<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** PBI 16: catatan Kepala SPPG dan jejak penyusunan snapshot rekap. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rekap_harians', function (Blueprint $table) {
            $table->text('catatan')->nullable();
            $table->foreignId('disusun_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('disusun_pada')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rekap_harians', function (Blueprint $table) {
            $table->dropConstrainedForeignId('disusun_oleh');
            $table->dropColumn(['catatan', 'disusun_pada']);
        });
    }
};
