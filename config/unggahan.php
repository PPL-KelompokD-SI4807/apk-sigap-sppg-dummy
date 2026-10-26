<?php

use App\Models\BuktiDistribusi;
use App\Models\FeedbackKeluhan;
use App\Models\LogProduksi;
use App\Models\PemeriksaanMutu;
use App\Models\PencairanDana;
use App\Models\Pengeluaran;

/*
|--------------------------------------------------------------------------
| Unggahan aman (NFR-06) — App\Services\Storage\SecureUploadService
|--------------------------------------------------------------------------
|
| - Jenis diterima ditentukan dari ISI berkas (finfo + tanda tangan byte + getimagesize),
|   bukan ekstensi/MIME klien. Nama berkas klien tidak pernah dipakai.
| - Disk privat 'uploads' (config/filesystems.php), jalur uploads/{sppg_id}/{modul}/{uuid}.{ext}.
| - Gambar di-encode ulang lewat GD (membuang EXIF/metadata/payload tersembunyi) dan diperkecil
|   ke maks_sisi_simpan_px. Tanpa GD: mode ketat (berkas dengan metadata/data sisipan ditolak).
*/

return [

    'disk' => 'uploads',

    /**
     * Unggahan tertunda (belum diikat lewat lampiran_ids) lebih tua dari ini dihapus berkas dan
     * barisnya oleh sigap:bersihkan-lampiran-tertunda (cegah disk penuh oleh berkas yatim).
     */
    'tertunda_ttl_jam' => 24,

    /** Unggahan tertunda aktif (belum diikat) maksimum per pengguna (cegah disk penuh). */
    'maks_tertunda_per_pengguna' => 10,

    /** Batas ukuran berkas masuk (byte). */
    'maks_byte' => 5 * 1024 * 1024,

    /** Sisi terpanjang gambar masuk (px); lebih besar ditolak. */
    'maks_sisi_input_px' => 8000,

    /** Batas jumlah piksel masuk (cegah "decompression bomb"; 30 MP ~ 120 MB memori GD). */
    'maks_piksel' => 30_000_000,

    /** Sisi terpanjang gambar yang disimpan setelah encode ulang (NFR-06: 1600 px). */
    'maks_sisi_simpan_px' => 1600,

    'kualitas_jpeg' => 85,
    'kualitas_webp' => 85,

    /** MIME terdeteksi -> ekstensi tersimpan. Hanya jenis ini yang diterima. */
    'mime' => [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ],

    /** Unggahan per menit per pengguna (RateLimiter 'unggah'). */
    'rate_limit_per_menit' => (int) env('SIGAP_UPLOAD_RATE_PER_MINUTE', 20),

    /*
     | Modul induk lampiran (allowlist polimorfik). Kunci = slug pada POST /lampiran dan segmen
     | {modul} jalur penyimpanan.
     |  - 'ability'  : hak Policy induk yang dibutuhkan untuk menambah lampiran ke record yang ADA
     |                 (penyajian selalu memakai 'view').
     |  - 'tertunda' : true = klien boleh mengunggah SEBELUM record induk dibuat (POST /lampiran tanpa
     |                 lampiranable_id), lalu mengirim `lampiran_ids` pada store/update modul
     |                 (PengikatLampiran). Hak unggah tertunda = hak C atau U modul 'rbac' (Tabel 5).
     |  - 'rbac'     : slug modul pada config/rbac.php untuk hak unggah tertunda.
     |  - 'kategori' : daftar kategori yang diizinkan (kategori kosong -> kategori pertama).
     |  - 'maks'     : jumlah maksimum lampiran per record (default 5).
     |  - 'sembunyikan_dari_peran' : peran yang boleh membaca record induk tetapi TIDAK boleh melihat
     |                 lampirannya (R06a-S-05: foto keluhan/mutu dapat memuat wajah/nama anak). Lampiran
     |                 dikosongkan di resource dan GET /lampiran/{id} membalas 403 `lampiran_disembunyikan`.
     */
    'modul' => [
        'bukti-distribusi' => ['model' => BuktiDistribusi::class, 'ability' => 'update'],
        'keuangan-pencairan' => ['model' => PencairanDana::class, 'ability' => 'update'],
        'keuangan-pengeluaran' => ['model' => Pengeluaran::class, 'ability' => 'update'],
        'keluhan' => ['model' => FeedbackKeluhan::class, 'ability' => 'update', 'tertunda' => true, 'rbac' => 'keluhan', 'kategori' => ['foto'], 'maks' => 5, 'sembunyikan_dari_peran' => ['investor']],
        'mutu' => ['model' => PemeriksaanMutu::class, 'ability' => 'update', 'tertunda' => true, 'rbac' => 'mutu', 'kategori' => ['foto'], 'maks' => 5, 'sembunyikan_dari_peran' => ['investor']],
        'log-produksi' => ['model' => LogProduksi::class, 'ability' => 'update', 'tertunda' => true, 'rbac' => 'log-produksi', 'kategori' => ['foto'], 'maks' => 5],
    ],

];
