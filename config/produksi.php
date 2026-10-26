<?php

return [

    /*
    | Faktor konversi satuan stok -> gram, untuk estimasi kebutuhan bahan (gram_per_porsi x porsi).
    | Satuan yang tidak tercantum (butir, ikat, ...) tidak dapat dibandingkan dengan stok.
    | Cairan dianggap berdensitas 1 g/ml.
    */
    'konversi_gram' => [
        'kg' => 1000,
        'g' => 1,
        'gram' => 1,
        'l' => 1000,
        'liter' => 1000,
        'ml' => 1,
    ],

    /* Lama perjalanan (menit) bila jam_rencana_tiba kosong, untuk deteksi bentrok armada/pengemudi. */
    'durasi_distribusi_default_menit' => 60,

    'mutu' => [
        /* Suhu makanan minimum (derajat C) agar boleh dinyatakan Layak (makanan panas). */
        'suhu_minimum_c' => (float) env('MUTU_SUHU_MINIMUM_C', 60),
    ],

];
