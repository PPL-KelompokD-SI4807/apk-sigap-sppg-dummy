<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-07 (dashboard): dua indeks tambahan untuk widget Admin. Aman: hanya menambah indeks (tanpa mengubah
 * atau menghapus data), idempoten (dilewati bila sudah ada), dan down() hanya membuang indeks ini.
 *
 * - audit_logs (event, created_at): hitungan login gagal per hari lintas SPPG (tanpa sppg_id di filter).
 * - telegram_updates (processed_at, created_at): webhook yang belum diproses (processed_at IS NULL).
 */
return new class extends Migration
{
    private const AUDIT = 'audit_logs_event_created_at_index';

    private const WEBHOOK = 'telegram_updates_processed_created_index';

    public function up(): void
    {
        if (! Schema::hasIndex('audit_logs', self::AUDIT)) {
            Schema::table('audit_logs', fn (Blueprint $table) => $table->index(['event', 'created_at'], self::AUDIT));
        }

        if (! Schema::hasIndex('telegram_updates', self::WEBHOOK)) {
            Schema::table('telegram_updates', fn (Blueprint $table) => $table->index(['processed_at', 'created_at'], self::WEBHOOK));
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('audit_logs', self::AUDIT)) {
            Schema::table('audit_logs', fn (Blueprint $table) => $table->dropIndex(self::AUDIT));
        }

        if (Schema::hasIndex('telegram_updates', self::WEBHOOK)) {
            Schema::table('telegram_updates', fn (Blueprint $table) => $table->dropIndex(self::WEBHOOK));
        }
    }
};
