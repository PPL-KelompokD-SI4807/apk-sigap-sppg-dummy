<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R07-1 (Q-13): klasifikasi penerima untuk Lampiran 30. Hanya ADD COLUMN nullable, tanpa backfill: data lama
 * tetap kosong (tidak ditebak) dan dilaporkan sebagai "belum terklasifikasi". Tanpa NIK/data pribadi tambahan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('penerima_manfaats', function (Blueprint $table) {
            $table->string('jenis_kelamin', 12)->nullable()->after('kelompok_sasaran');
            $table->string('jenjang', 20)->nullable()->after('jenis_kelamin');
            $table->string('jenis_satuan', 20)->nullable()->after('jenjang');
        });
    }

    public function down(): void
    {
        Schema::table('penerima_manfaats', function (Blueprint $table) {
            $table->dropColumn(['jenis_kelamin', 'jenjang', 'jenis_satuan']);
        });
    }
};
