<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R07-1: identitas SPPG untuk laporan format BGN (Lampiran 30). Hanya ADD COLUMN nullable, tanpa backfill;
 * `kode` dan data lama tidak diubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sppgs', function (Blueprint $table) {
            $table->string('id_sppg_bgn', 30)->nullable()->after('kode');
            $table->string('kecamatan', 100)->nullable()->after('alamat');
            $table->string('kab_kota', 100)->nullable()->after('kecamatan');
            $table->string('provinsi', 100)->nullable()->after('kab_kota');
            $table->string('no_va', 50)->nullable()->after('telepon');
            $table->string('nama_ketua_yayasan', 150)->nullable()->after('mitra_yayasan');
        });
    }

    public function down(): void
    {
        Schema::table('sppgs', function (Blueprint $table) {
            $table->dropColumn(['id_sppg_bgn', 'kecamatan', 'kab_kota', 'provinsi', 'no_va', 'nama_ketua_yayasan']);
        });
    }
};
