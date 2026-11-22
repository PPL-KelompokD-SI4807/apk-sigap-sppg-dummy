<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R07-1: saldo awal (diisi Akuntan/Admin lewat endpoint khusus) dan pengaturan anggaran per SPPG. Kolom anggaran
 * kosong = kode memakai nilai bawaan (8000/10000/6000000/6); TIDAK diisi di DB. Hanya ADD COLUMN nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sppgs', function (Blueprint $table) {
            $table->decimal('saldo_awal', 15, 2)->nullable()->after('kapasitas_porsi_harian');
            $table->date('saldo_awal_tanggal')->nullable()->after('saldo_awal');
            $table->unsignedInteger('acuan_bahan_baku_kecil')->nullable()->after('saldo_awal_tanggal');
            $table->unsignedInteger('acuan_bahan_baku_lainnya')->nullable()->after('acuan_bahan_baku_kecil');
            $table->unsignedBigInteger('insentif_fasilitas_per_hari')->nullable()->after('acuan_bahan_baku_lainnya');
            $table->unsignedTinyInteger('insentif_fasilitas_maks_hari_minggu')->nullable()->after('insentif_fasilitas_per_hari');
        });
    }

    public function down(): void
    {
        Schema::table('sppgs', function (Blueprint $table) {
            $table->dropColumn([
                'saldo_awal', 'saldo_awal_tanggal', 'acuan_bahan_baku_kecil', 'acuan_bahan_baku_lainnya',
                'insentif_fasilitas_per_hari', 'insentif_fasilitas_maks_hari_minggu',
            ]);
        });
    }
};
