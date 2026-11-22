<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R-06b-2: pengeluaran non-menu wajib disetujui investor. Kolom baru default `diajukan` (fail-closed
 * untuk baris baru). Baris yang SUDAH ada di-backfill `disetujui`: berasal dari dokumen sumber dan
 * sudah dihitung dalam anggaran; menjadikannya `diajukan` akan menurunkan serapan secara diam-diam.
 * Backfill HANYA menyentuh kolom status_persetujuan (tanpa menghapus/mengubah data lain).
 *
 * Operator (MySQL): DDL tidak transaksional, migrasi ini TIDAK idempoten bila gagal di tengah (kolom sudah ada).
 * Jalankan di jam sepi setelah cadangan; bila gagal, periksa kolom/indeks/FK yang sempat terbentuk, hapus manual,
 * lalu ulangi. Bila hanya backfill yang terputus: UPDATE pengeluarans SET status_persetujuan='disetujui' WHERE
 * status_persetujuan='diajukan' AND created_at < <waktu mulai migrasi> (lihat docs/decisions/2026-10-27-tinjauan-keamanan-r06b2.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pengeluarans', function (Blueprint $table) {
            $table->string('status_persetujuan', 20)->default('diajukan')->after('melebihi_anggaran');
            $table->unsignedInteger('versi')->default(1)->after('status_persetujuan');
            $table->unsignedBigInteger('pembelian_bahan_id')->nullable()->unique()->after('versi');
            $table->index(['sppg_id', 'status_persetujuan', 'tanggal'], 'pengeluarans_sppg_status_tanggal_index');
        });

        // Backfill: semua baris lama (termasuk yang terhapus lunak) dianggap sudah disetujui. Dikerjakan per
        // kelompok agar kunci baris singkat pada tabel besar (R-06b-2 L3). Pada titik ini semua baris lama masih
        // bernilai bawaan `diajukan`, jadi kondisi `where` membuat perulangan berhenti sendiri dan aman diulang.
        do {
            $terubah = DB::table('pengeluarans')->where('status_persetujuan', 'diajukan')->limit(5000)->update(['status_persetujuan' => 'disetujui']);
        } while ($terubah > 0);

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            // SQLite tidak dapat menambah FK pada tabel yang sudah ada; integritas dijaga service (lingkungan uji).
            return;
        }

        Schema::table('pembelian_bahans', function (Blueprint $table) {
            $table->foreign('pengeluaran_id')->references('id')->on('pengeluarans')->nullOnDelete();
        });

        Schema::table('pengeluarans', function (Blueprint $table) {
            $table->foreign('pembelian_bahan_id')->references('id')->on('pembelian_bahans')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('pengeluarans', function (Blueprint $table) {
                $table->dropForeign(['pembelian_bahan_id']);
            });

            Schema::table('pembelian_bahans', function (Blueprint $table) {
                $table->dropForeign(['pengeluaran_id']);
            });
        }

        Schema::table('pengeluarans', function (Blueprint $table) {
            $table->dropIndex('pengeluarans_sppg_status_tanggal_index');
            $table->dropUnique(['pembelian_bahan_id']);
            $table->dropColumn(['status_persetujuan', 'versi', 'pembelian_bahan_id']);
        });
    }
};
