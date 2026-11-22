<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** R07-1 (Q-13): Food Sample dan Uji Organoleptik adalah porsi (bukan orang). Nullable, kosong = 0; tanpa backfill. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('log_produksis', function (Blueprint $table) {
            $table->unsignedInteger('porsi_food_sample')->nullable()->after('realisasi_porsi');
            $table->unsignedInteger('porsi_uji_organoleptik')->nullable()->after('porsi_food_sample');
        });
    }

    public function down(): void
    {
        Schema::table('log_produksis', function (Blueprint $table) {
            $table->dropColumn(['porsi_food_sample', 'porsi_uji_organoleptik']);
        });
    }
};
