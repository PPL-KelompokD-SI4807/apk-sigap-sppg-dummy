<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unggahan "tertunda": klien mengunggah berkas (POST /lampiran tanpa lampiranable_id) sebelum
 * record induk dibuat, lalu mengirim lampiran_ids pada store/update modul. Selama belum terikat,
 * lampiranable_type/id NULL dan kolom `modul` mencatat modul tujuan. Perubahan hanya melonggarkan
 * nullability + kolom baru (data lama tidak berubah).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lampirans', function (Blueprint $table) {
            $table->string('lampiranable_type')->nullable()->change();
            $table->unsignedBigInteger('lampiranable_id')->nullable()->change();
            $table->string('modul', 40)->nullable()->after('uploaded_by');
            $table->index(['uploaded_by', 'lampiranable_id'], 'lampirans_uploader_terikat_index');
        });
    }

    public function down(): void
    {
        Schema::table('lampirans', function (Blueprint $table) {
            $table->dropIndex('lampirans_uploader_terikat_index');
            $table->dropColumn('modul');
        });
    }
};
