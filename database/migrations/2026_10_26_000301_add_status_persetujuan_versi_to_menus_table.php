<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-06b-1: status persetujuan investor + nomor versi menu. Aditif dengan default: baris lama menjadi
 * `draf` (tidak ada persetujuan diam-diam) dan `versi` 1. Mengubah menu menaikkan versi (lihat
 * PersetujuanMenuService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->string('status_persetujuan', 20)->default('draf')->after('catatan');
            $table->unsignedInteger('versi')->default(1)->after('status_persetujuan');
            $table->index(['sppg_id', 'status_persetujuan']);
        });
    }

    public function down(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->dropIndex(['sppg_id', 'status_persetujuan']);
            $table->dropColumn(['status_persetujuan', 'versi']);
        });
    }
};
