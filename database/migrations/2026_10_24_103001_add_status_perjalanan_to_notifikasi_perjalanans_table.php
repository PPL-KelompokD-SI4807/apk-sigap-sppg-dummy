<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PBI 2: status siklus perjalanan (berangkat -> dalam_perjalanan -> tiba/dibatalkan) dan data
 * estimasi tiba. `status_kirim` tetap khusus status pengiriman pesan bot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifikasi_perjalanans', function (Blueprint $table) {
            $table->string('status_perjalanan', 20)->default('berangkat');
            $table->dateTime('waktu_berangkat')->nullable();
            $table->dateTime('waktu_tiba')->nullable();
            $table->dateTime('waktu_dibatalkan')->nullable();
            $table->text('alasan_batal')->nullable();
            $table->unsignedSmallInteger('estimasi_menit')->nullable();
            $table->string('estimasi_sumber', 10)->nullable();
            $table->dateTime('terlambat_dideteksi_pada')->nullable();
            $table->index(['status_perjalanan', 'estimasi_tiba'], 'notif_perjalanan_status_estimasi_idx');
            $table->index(['sppg_id', 'status_perjalanan'], 'notif_perjalanan_sppg_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('notifikasi_perjalanans', function (Blueprint $table) {
            $table->dropIndex('notif_perjalanan_status_estimasi_idx');
            $table->dropIndex('notif_perjalanan_sppg_status_idx');
            $table->dropColumn([
                'status_perjalanan', 'waktu_berangkat', 'waktu_tiba', 'waktu_dibatalkan', 'alasan_batal',
                'estimasi_menit', 'estimasi_sumber', 'terlambat_dideteksi_pada',
            ]);
        });
    }
};
