<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PBI 9: relawan unit distribusi hanya boleh mengubah jadwal distribusi buatannya sendiri pada
 * hari yang sama (owner_rules). Tabel hanya punya `petugas_id` (penugasan), jadi pembuat dicatat
 * terpisah di `dibuat_oleh`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jadwal_distribusis', function (Blueprint $table) {
            $table->foreignId('dibuat_oleh')->nullable()->after('petugas_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('jadwal_distribusis', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dibuat_oleh');
        });
    }
};
