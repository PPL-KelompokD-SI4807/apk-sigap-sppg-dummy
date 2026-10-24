<?php

return [

    /*
    | Ambang "akan kedaluwarsa" (hari) untuk /bahan-baku/kedaluwarsa-dekat dan sigap:cek-stok.
    | Proposal FR-04: kedaluwarsa <= 3 hari.
    */
    'kedaluwarsa_hari' => (int) env('GUDANG_KEDALUWARSA_HARI', 3),

    /* Jam pemeriksaan harian sigap:cek-stok (WIB, zona waktu aplikasi). */
    'cek_stok_jam' => env('GUDANG_CEK_STOK_JAM', '06:00'),

    /* Masa berlaku penanda anti-spam peringatan (jam); satu peringatan per kondisi per hari. */
    'penanda_ttl_jam' => 36,

];
