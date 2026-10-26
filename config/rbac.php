<?php

/*
|--------------------------------------------------------------------------
| Matriks CRUD hak akses (Tabel 5 proposal SiGAP SPPG) — SUMBER KEBENARAN RBAC
|--------------------------------------------------------------------------
|
| Format: 'modul-slug' => ['role' => 'huruf'], huruf dari C (Create), R (Read),
| U (Update), D (Delete; selalu hapus lunak + log audit). String kosong = "–" (tanpa akses).
| Urutan modul mengikuti nomor fitur Subbab 5.2 (jangan diubah).
|
| Keterangan khusus (lihat juga 'unit_rules' dan 'owner_rules'):
| - Tanda "*" (mis. "R*" modul pengguna) = huruf itu hanya berlaku untuk record milik
|   pengguna sendiri (profil sendiri); daftar (viewAny) ditolak. Lihat Rbac::isSelfOnly().
| - "C" pada notifikasi-perjalanan = memicu pengiriman notifikasi.
| - "RU" pada keluhan (kepala_sppg/ahli_gizi) = memperbarui status & catatan tindak lanjut.
| - "CRU" pada rekap-harian = membuat/mengirim ulang rekap dan mengatur pengawas penerima.
| - Relawan: "CRU (relawan)" jadwal = unit dapur -> jadwal produksi, unit distribusi ->
|   jadwal distribusi; "CR (distribusi)"/"CRU (distribusi)" hanya unit distribusi;
|   "CRU (dapur)" hanya unit dapur. Relawan hanya boleh mengubah data buatannya sendiri
|   pada hari yang sama ('owner_rules').
|
| - Investor (R-06a, pemilik SPPG): HANYA baca (tanpa C/U/D di modul mana pun). Satu investor dapat
|   memiliki banyak SPPG (pivot investor_sppg); data selalu dibatasi ke SATU SPPG aktif (header
|   X-SPPG-Id, App\Support\SppgAktif). Penerima manfaat hanya lewat agregat ('agregat' di bawah).
|
| Agen AI mewarisi matriks ini: tool untuk sel kosong tidak pernah ditawarkan ke LLM.
*/

return [

    'modules' => [
        'pengguna' => [
            'label' => 'Data Pengguna (Login Akun)',
            'admin' => 'CRUD', 'kepala_sppg' => 'R', 'ahli_gizi' => 'R*', 'akuntan' => 'R*', 'staf_gudang' => 'R*', 'relawan' => 'R*', 'investor' => 'R*',
        ],
        'notifikasi-perjalanan' => [
            'label' => 'Notifikasi Perjalanan Mobil ke Tujuan',
            'admin' => 'R', 'kepala_sppg' => 'CR', 'ahli_gizi' => '', 'akuntan' => '', 'staf_gudang' => '', 'relawan' => 'CR', 'investor' => '',
        ],
        'sppg' => [
            'label' => 'Data SPPG',
            'admin' => 'CRUD', 'kepala_sppg' => 'RU', 'ahli_gizi' => 'R', 'akuntan' => 'R', 'staf_gudang' => 'R', 'relawan' => 'R', 'investor' => 'R',
        ],
        'titik-layanan' => [
            'label' => 'Titik Layanan',
            'admin' => 'CRUD', 'kepala_sppg' => 'CRUD', 'ahli_gizi' => 'R', 'akuntan' => 'R', 'staf_gudang' => 'R', 'relawan' => 'R', 'investor' => '',
        ],
        'penerima-manfaat' => [
            'label' => 'Penerima Manfaat',
            'admin' => 'CRUD', 'kepala_sppg' => 'CRU', 'ahli_gizi' => 'R', 'akuntan' => '', 'staf_gudang' => '', 'relawan' => '', 'investor' => '',
        ],
        'bahan-baku' => [
            'label' => 'Bahan Baku (+ mutasi stok)',
            'admin' => 'CRUD', 'kepala_sppg' => 'R', 'ahli_gizi' => 'R', 'akuntan' => 'R', 'staf_gudang' => 'CRUD', 'relawan' => '', 'investor' => 'R',
        ],
        'pemasok' => [
            'label' => 'Pemasok',
            'admin' => 'CRUD', 'kepala_sppg' => 'R', 'ahli_gizi' => 'R', 'akuntan' => 'R', 'staf_gudang' => 'CRU', 'relawan' => '', 'investor' => '',
        ],
        'menu-gizi' => [
            'label' => 'Menu dan Gizi',
            'admin' => 'R', 'kepala_sppg' => 'R', 'ahli_gizi' => 'CRUD', 'akuntan' => '', 'staf_gudang' => 'R', 'relawan' => 'R', 'investor' => '',
        ],
        'jadwal' => [
            'label' => 'Jadwal Produksi dan Distribusi',
            'admin' => 'R', 'kepala_sppg' => 'CRUD', 'ahli_gizi' => 'CRU', 'akuntan' => '', 'staf_gudang' => 'R', 'relawan' => 'CRU', 'investor' => '',
        ],
        'log-produksi' => [
            'label' => 'Log Produksi Harian',
            'admin' => 'R', 'kepala_sppg' => 'RUD', 'ahli_gizi' => 'R', 'akuntan' => 'R', 'staf_gudang' => 'R', 'relawan' => 'CRU', 'investor' => '',
        ],
        'mutu' => [
            'label' => 'Pemeriksaan Mutu dan Keamanan Pangan',
            'admin' => 'R', 'kepala_sppg' => 'R', 'ahli_gizi' => 'CRUD', 'akuntan' => '', 'staf_gudang' => '', 'relawan' => 'R', 'investor' => 'R',
        ],
        'armada' => [
            'label' => 'Armada dan Pengemudi',
            'admin' => 'CRUD', 'kepala_sppg' => 'CRUD', 'ahli_gizi' => '', 'akuntan' => 'R', 'staf_gudang' => '', 'relawan' => 'R', 'investor' => '',
        ],
        'bukti-distribusi' => [
            'label' => 'Bukti Distribusi',
            'admin' => 'R', 'kepala_sppg' => 'CRUD', 'ahli_gizi' => 'R', 'akuntan' => 'R', 'staf_gudang' => '', 'relawan' => 'CRU', 'investor' => '',
        ],
        'keuangan' => [
            'label' => 'Data Keuangan (Pencairan Dana & Pengeluaran)',
            'admin' => 'R', 'kepala_sppg' => 'R', 'ahli_gizi' => '', 'akuntan' => 'CRUD', 'staf_gudang' => '', 'relawan' => '', 'investor' => 'R',
        ],
        'keluhan' => [
            'label' => 'Feedback dan Keluhan',
            'admin' => 'CRUD', 'kepala_sppg' => 'RU', 'ahli_gizi' => 'RU', 'akuntan' => '', 'staf_gudang' => '', 'relawan' => 'CR', 'investor' => 'R',
        ],
        'rekap-harian' => [
            'label' => 'Laporan Rekapitulasi Harian Operasional',
            'admin' => 'R', 'kepala_sppg' => 'CRU', 'ahli_gizi' => 'R', 'akuntan' => 'R', 'staf_gudang' => '', 'relawan' => '', 'investor' => 'R',
        ],
        // R-06b-1 (tambahan di luar 16 fitur Subbab 5.2): C = mengajukan menu; keputusan lewat 'keputusan'.
        'persetujuan-menu' => [
            'label' => 'Persetujuan Menu oleh Investor',
            'admin' => 'R', 'kepala_sppg' => 'R', 'ahli_gizi' => 'CR', 'akuntan' => '', 'staf_gudang' => '', 'relawan' => '', 'investor' => 'R',
        ],
    ],

    /*
    | Hak KEPUTUSAN (R-06b-1): peran yang boleh memutuskan pengajuan pada modul. Sengaja BUKAN huruf
    | C/U/D agar investor tetap Rbac::readOnly() (lampiran dan modul lain tetap tertutup). Admin,
    | kepala, ahli gizi, dan peran lain tidak pernah memutuskan. Lihat Rbac::canDecide().
    */
    'keputusan' => [
        'persetujuan-menu' => ['investor'],
    ],

    /*
    | Pembatasan per unit relawan. Bila modul tercantum, relawan dengan unit yang tidak
    | tercantum di 'units' TIDAK punya akses apa pun ke modul tersebut.
    | 'jenis' memetakan unit -> sub-jenis data yang boleh dikelola (dibaca lewat
    | Rbac::unitScope()); mis. jadwal: dapur -> 'produksi', distribusi -> 'distribusi'.
    */
    'unit_rules' => [
        'relawan' => [
            'notifikasi-perjalanan' => ['units' => ['distribusi']],
            'bukti-distribusi' => ['units' => ['distribusi']],
            'log-produksi' => ['units' => ['dapur']],
            'jadwal' => [
                'units' => ['dapur', 'distribusi'],
                'jenis' => ['dapur' => 'produksi', 'distribusi' => 'distribusi'],
            ],
        ],
    ],

    /*
    | Akses agregat saja (hitungan per kategori, tanpa nama/data pribadi) untuk peran yang selnya
    | kosong di matriks. Peran dengan hak R (bukan R*) pada modul juga boleh membaca agregat.
    | Lihat Rbac::canAggregate().
    */
    'agregat' => [
        'penerima-manfaat' => ['investor'],
    ],

    /*
    | Ambang sel kecil agregat (R06a-S-02, docs/decisions/2026-10-26-ambang-agregat-k5.md). Untuk peran
    | yang HANYA punya hak agregat (Rbac::aggregateOnlyModules), setiap hitungan 1..k-1 disamarkan menjadi
    | null + penanda, termasuk sel pelengkap (suppression sekunder) agar tidak dapat dihitung dari
    | total/aktif. Peran ber-hak R penuh tetap melihat angka asli. Nilai k minimal 2.
    */
    'agregat_ambang' => [
        'k' => 5,
    ],

    /*
    | "Relawan hanya dapat mengubah data yang ia buat sendiri pada hari yang sama."
    | Diterapkan BaseSppgPolicy::update() memakai kolom $ownerColumn (default created_by).
    */
    'owner_rules' => [
        'relawan' => ['U' => 'own_same_day', 'D' => 'own_same_day'],
    ],

];
