<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R04-2 (ADR 2026-11-09 Keputusan 5, A4/A12): penguncian KANAL WEB terpisah dari Mini App.
 *
 * Aditif saja (tidak mengubah/menghapus data). Kolom Mini App (`failed_login_attempts`, `locked_until`) tidak
 * disentuh: kegagalan login web tidak pernah mengunci Mini App, dan sebaliknya.
 * - web_gagal_login            : gagal sandi/kode web sejak login web berhasil terakhir (siklus kunci saat ini).
 * - web_terkunci_sampai        : kanal web terkunci sampai waktu ini (null = tidak terkunci).
 * - web_terkunci_terakhir_pada : awal kunci terakhir; kunci berulang dalam 24 jam -> 60 menit (bukan 15).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('web_gagal_login')->default(0)->after('locked_until');
            $table->timestamp('web_terkunci_sampai')->nullable()->after('web_gagal_login');
            $table->timestamp('web_terkunci_terakhir_pada')->nullable()->after('web_terkunci_sampai');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['web_gagal_login', 'web_terkunci_sampai', 'web_terkunci_terakhir_pada']);
        });
    }
};
