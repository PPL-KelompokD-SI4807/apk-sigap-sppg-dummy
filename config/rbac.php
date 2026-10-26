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
| Agen AI mewarisi matriks ini: tool untuk sel kosong tidak pernah ditawarkan ke LLM.
*/

return [

    'modules' => [
        'pengguna' => [
            'label' => 'Data Pengguna (Login Akun)',
            'admin' => 'CRUD', 'kepala_sppg' => 'R', 'ahli_gizi' => 'R*', 'akuntan' => 'R*', 'staf_gudang' => 'R*', 'relawan' => 'R*',
        ],
        'notifikasi-perjalanan' => [
            'label' => 'Notifikasi Perjalanan Mobil ke Tujuan',
            'admin' => 'R', 'kepala_sppg' => 'CR', 'ahli_gizi' => '', 'akuntan' => '', 'staf_gudang' => '', 'relawan' => 'CR',
        ],
        'sppg' => [
            'label' => 'Data SPPG',
            'admin' => 'CRUD', 'kepala_sppg' => 'RU', 'ahli_gizi' => 'R', 'akuntan' => 'R', 'staf_gudang' => 'R', 'relawan' => 'R',
        ],
        'titik-layanan' => [
            'label' => 'Titik Layanan',
            'admin' => 'CRUD', 'kepala_sppg' => 'CRUD', 'ahli_gizi' => 'R', 'akuntan' => 'R', 'staf_gudang' => 'R', 'relawan' => 'R',
        ],
        'penerima-manfaat' => [
            'label' => 'Penerima Manfaat',
            'admin' => 'CRUD', 'kepala_sppg' => 'CRU', 'ahli_gizi' => 'R', 'akuntan' => '', 'staf_gudang' => '', 'relawan' => '',
        ],
        'bahan-baku' => [
            'label' => 'Bahan Baku (+ mutasi stok)',
            'admin' => 'CRUD', 'kepala_sppg' => 'R', 'ahli_gizi' => 'R', 'akuntan' => 'R', 'staf_gudang' => 'CRUD', 'relawan' => '',
        ],
        'pemasok' => [
            'label' => 'Pemasok',
            'admin' => 'CRUD', 'kepala_sppg' => 'R', 'ahli_gizi' => 'R', 'akuntan' => 'R', 'staf_gudang' => 'CRU', 'relawan' => '',
        ],
        'menu-gizi' => [
            'label' => 'Menu dan Gizi',
            'admin' => 'R', 'kepala_sppg' => 'R', 'ahli_gizi' => 'CRUD', 'akuntan' => '', 'staf_gudang' => 'R', 'relawan' => 'R',
        ],
        'jadwal' => [
            'label' => 'Jadwal Produksi dan Distribusi',
            'admin' => 'R', 'kepala_sppg' => 'CRUD', 'ahli_gizi' => 'CRU', 'akuntan' => '', 'staf_gudang' => 'R', 'relawan' => 'CRU',
        ],
        'log-produksi' => [
            'label' => 'Log Produksi Harian',
            'admin' => 'R', 'kepala_sppg' => 'RUD', 'ahli_gizi' => 'R', 'akuntan' => 'R', 'staf_gudang' => 'R', 'relawan' => 'CRU',
        ],
        'mutu' => [
            'label' => 'Pemeriksaan Mutu dan Keamanan Pangan',
            'admin' => 'R', 'kepala_sppg' => 'R', 'ahli_gizi' => 'CRUD', 'akuntan' => '', 'staf_gudang' => '', 'relawan' => 'R',
        ],
        'armada' => [
            'label' => 'Armada dan Pengemudi',
            'admin' => 'CRUD', 'kepala_sppg' => 'CRUD', 'ahli_gizi' => '', 'akuntan' => 'R', 'staf_gudang' => '', 'relawan' => 'R',
        ],
        'bukti-distribusi' => [
            'label' => 'Bukti Distribusi',
            'admin' => 'R', 'kepala_sppg' => 'CRUD', 'ahli_gizi' => 'R', 'akuntan' => 'R', 'staf_gudang' => '', 'relawan' => 'CRU',
        ],
        'keuangan' => [
            'label' => 'Data Keuangan (Pencairan Dana & Pengeluaran)',
            'admin' => 'R', 'kepala_sppg' => 'R', 'ahli_gizi' => '', 'akuntan' => 'CRUD', 'staf_gudang' => '', 'relawan' => '',
        ],
        'keluhan' => [
            'label' => 'Feedback dan Keluhan',
            'admin' => 'CRUD', 'kepala_sppg' => 'RU', 'ahli_gizi' => 'RU', 'akuntan' => '', 'staf_gudang' => '', 'relawan' => 'CR',
        ],
        'rekap-harian' => [
            'label' => 'Laporan Rekapitulasi Harian Operasional',
            'admin' => 'R', 'kepala_sppg' => 'CRU', 'ahli_gizi' => 'R', 'akuntan' => 'R', 'staf_gudang' => '', 'relawan' => '',
        ],
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
    | "Relawan hanya dapat mengubah data yang ia buat sendiri pada hari yang sama."
    | Diterapkan BaseSppgPolicy::update() memakai kolom $ownerColumn (default created_by).
    */
    'owner_rules' => [
        'relawan' => ['U' => 'own_same_day', 'D' => 'own_same_day'],
    ],

];
