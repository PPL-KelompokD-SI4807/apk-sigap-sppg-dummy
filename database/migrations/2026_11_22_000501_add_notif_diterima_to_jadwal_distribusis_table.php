<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #177: status kirim pesan konfirmasi "diterima" ke PIC titik layanan (menunggu/terkirim/gagal/dialihkan_ke_kepala)
 * dan waktunya. Aditif: dua kolom nullable; baris lama tetap null (belum ada konfirmasi); tanpa mengubah data.
 * Status kirim pesan "berangkat" tetap di notifikasi_perjalanans.status_kirim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jadwal_distribusis', function (Blueprint $table) {
            $table->string('notif_diterima_status', 24)->nullable();
            $table->dateTime('notif_diterima_waktu')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('jadwal_distribusis', function (Blueprint $table) {
            $table->dropColumn(['notif_diterima_status', 'notif_diterima_waktu']);
        });
    }
};
