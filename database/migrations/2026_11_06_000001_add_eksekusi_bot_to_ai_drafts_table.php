<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eksekusi aksi Agen AI dari chat (Tahap 1): draf dapat dijalankan lewat tombol konfirmasi Bot.
 *
 * Migrasi ADITIF, tidak menghapus/mengubah isi baris:
 * - sppg_id menjadi nullable: draf milik Admin (lintas SPPG, users.sppg_id null), mis. undangan pengguna.
 *   Baris lama tetap terisi; FK ke sppgs tetap ada.
 * - status diperlebar 20 -> 32 karakter untuk nilai baru `menunggu_konfirmasi_ulang` (konfirmasi dua langkah),
 *   `cancelled`, `gagal`. Nilai lama tidak berubah.
 * - tool: nama tool pembuat draf (membedakan tool sejenis pada modul + aksi yang sama, mis. nonaktifkan vs aktifkan).
 * - sidik_target: sidik keadaan record target saat draf ubah/hapus dibuat (diisi server); berbeda saat
 *   dijalankan -> "data berubah, ulangi permintaan", draf tidak dijalankan.
 * - hasil: ringkasan hasil eksekusi (kode + id saja). TIDAK PERNAH memuat token/tautan undangan atau rahasia.
 * - konfirmasi_pertama_at: waktu langkah 1 dari konfirmasi dua langkah (aksi berbahaya).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_drafts', function (Blueprint $table) {
            $table->unsignedBigInteger('sppg_id')->nullable()->change();
            $table->string('status', 32)->default('pending')->change();
            $table->string('tool', 64)->nullable()->after('action');
            $table->char('sidik_target', 16)->nullable()->after('target_id');
            $table->json('hasil')->nullable()->after('confidence');
            $table->dateTime('konfirmasi_pertama_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        // sppg_id nullable dan lebar status TIDAK dikembalikan: baris draf Admin (sppg_id null) atau status baru
        // akan gagal/terpotong. Hanya kolom tambahan yang dibuang.
        Schema::table('ai_drafts', function (Blueprint $table) {
            $table->dropColumn(['tool', 'sidik_target', 'hasil', 'konfirmasi_pertama_at']);
        });
    }
};
