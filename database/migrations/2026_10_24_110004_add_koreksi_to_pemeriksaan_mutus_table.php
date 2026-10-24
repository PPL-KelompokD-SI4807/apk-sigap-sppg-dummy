<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PBI 11: pemeriksaan yang sudah memengaruhi distribusi tidak boleh dihapus; hanya dikoreksi dengan
 * catatan. Catatan, waktu, dan pelaku koreksi disimpan di baris (riwayat nilai lama/baru ada di audit_logs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pemeriksaan_mutus', function (Blueprint $table) {
            $table->text('catatan_koreksi')->nullable()->after('foto_path');
            $table->dateTime('dikoreksi_pada')->nullable()->after('catatan_koreksi');
            $table->foreignId('dikoreksi_oleh')->nullable()->after('dikoreksi_pada')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pemeriksaan_mutus', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dikoreksi_oleh');
            $table->dropColumn(['catatan_koreksi', 'dikoreksi_pada']);
        });
    }
};
