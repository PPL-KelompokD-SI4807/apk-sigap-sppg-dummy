<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Memecah checklist organoleptik menjadi tiga butir (rasa, aroma, tampilan). Aditif:
 * organoleptik_baik TETAP ada sebagai nilai turunan (true bila ketiganya true). Data lama diisi
 * dari organoleptik_baik (tiga butir sama dengan nilai lama).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pemeriksaan_mutus', function (Blueprint $table) {
            $table->boolean('rasa_baik')->default(true)->after('kebersihan_petugas');
            $table->boolean('aroma_baik')->default(true)->after('rasa_baik');
            $table->boolean('tampilan_baik')->default(true)->after('aroma_baik');
        });

        DB::table('pemeriksaan_mutus')->update([
            'rasa_baik' => DB::raw('organoleptik_baik'),
            'aroma_baik' => DB::raw('organoleptik_baik'),
            'tampilan_baik' => DB::raw('organoleptik_baik'),
        ]);
    }

    public function down(): void
    {
        Schema::table('pemeriksaan_mutus', function (Blueprint $table) {
            $table->dropColumn(['rasa_baik', 'aroma_baik', 'tampilan_baik']);
        });
    }
};
