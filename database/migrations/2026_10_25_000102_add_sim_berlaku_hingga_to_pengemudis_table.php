<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Masa berlaku SIM pengemudi (validasi penugasan armada). Nullable: SIM tidak wajib dicatat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pengemudis', function (Blueprint $table) {
            $table->date('sim_berlaku_hingga')->nullable()->after('no_sim');
        });
    }

    public function down(): void
    {
        Schema::table('pengemudis', function (Blueprint $table) {
            $table->dropColumn('sim_berlaku_hingga');
        });
    }
};
