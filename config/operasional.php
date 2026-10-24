<?php

/*
|--------------------------------------------------------------------------
| Parameter logika operasional harian (PBI 2 Notifikasi Perjalanan, PBI 16 Rekap Harian)
|--------------------------------------------------------------------------
|
| Zona waktu bisnis selalu Asia/Jakarta (WIB). Nilai dapat diubah lewat .env.example-nya.
*/

return [

    'timezone' => 'Asia/Jakarta',

    'perjalanan' => [
        /*
         | Estimasi tiba (TripNotificationService):
         |   durasi_menit = ceil(jarak_km / kecepatan_rata_rata_kmj * 60) + buffer_menit
         |   dibatasi ke [durasi_minimum_menit, durasi_maksimum_menit]
         |   estimasi_tiba = waktu_berangkat + durasi_menit
         | Prioritas sumber: input manual > jarak titik layanan > selisih jam jadwal > durasi_default_menit.
         */
        'kecepatan_rata_rata_kmj' => (float) env('SIGAP_TRIP_SPEED_KMH', 30),
        'buffer_menit' => (int) env('SIGAP_TRIP_BUFFER_MINUTES', 5),
        'durasi_minimum_menit' => 5,
        'durasi_maksimum_menit' => 720,
        'durasi_default_menit' => 30,
    ],

    'bukti_distribusi' => [
        /*
         | Deteksi keterlambatan (BuktiDistribusiService):
         |   acuan = estimasi_tiba perjalanan terakhir (tidak dibatalkan) > tanggal_distribusi + jam_rencana_tiba
         |   menit_keterlambatan = waktu_tiba - acuan (menit, > 0), terlambat = menit > toleransi.
         */
        'toleransi_terlambat_menit' => (int) env('SIGAP_DISTRIBUSI_TOLERANSI_MENIT', 15),

        /*
         | Bukti belum masuk (sigap:cek-bukti-distribusi, tiap 10 menit): mobil sudah berangkat tetapi
         | bukti belum dicatat setelah jam_rencana_tiba + batas ini -> event BuktiDistribusiBelumMasuk
         | (sekali per jadwal).
         */
        'batas_belum_masuk_menit' => (int) env('SIGAP_BUKTI_BELUM_MASUK_MENIT', 60),
    ],

    'keuangan' => [
        /*
         | FR-09/FR-10: pengeluaran yang melebihi sisa anggaran periode DICATAT dan ditandai
         | (melebihi_anggaran=true + event AnggaranMelampaui), karena data dicatat dari dokumen
         | sumber. Set true untuk menolaknya (422).
         */
        'tolak_melebihi_anggaran' => (bool) env('SIGAP_TOLAK_MELEBIHI_ANGGARAN', false),
        'ambang_peringatan_persen' => 80,
    ],

    'rekap' => [
        'jam_kirim' => '17:00',
        'hari_peringatan_kedaluwarsa' => 3,
    ],

];
