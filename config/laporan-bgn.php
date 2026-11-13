<?php

/*
|--------------------------------------------------------------------------
| Ekspor PDF laporan BGN Lampiran 30a-30d (R07-3)
|--------------------------------------------------------------------------
| Pustaka: setasign/fpdf di belakang antarmuka App\Services\LaporanBgn\Pdf\PdfRenderer.
| Batas memori kontainer queue 192 MB (docker-compose.prod.yml); target puncak <= ~120 MB.
*/

return [

    /** Ukuran kertas Folio/F4 (mm, potret: lebar x tinggi), Keputusan Q-11. */
    'kertas_mm' => [210.0, 330.0],

    /** 30d-C: bawaan potret mengikuti juknis (font diperkecil); true = lanskap bila terlalu sempit. */
    'penerima_lanskap' => (bool) env('LAPORAN_BGN_PENERIMA_LANSKAP', false),

    /** Jumlah maksimum foto bukti (nota) pada halaman lampiran per berkas (batas memori). */
    'maks_bukti' => 20,

    /** Sisi terpanjang foto bukti setelah diperkecil GD (px) dan kualitas JPEG. */
    'bukti_maks_sisi_px' => 900,
    'bukti_kualitas_jpeg' => 75,

    /** Batas piksel foto masuk sebelum didekode GD (cegah lonjakan memori). */
    'bukti_maks_piksel' => 4_000_000,

    /** Panjang maksimum teks pengguna per sel (karakter) sebelum dipotong dengan "...". */
    'maks_teks_sel' => 80,

    /** Permintaan PDF per menit per pengguna. */
    'rate_limit_per_menit' => (int) env('LAPORAN_BGN_PDF_RATE_PER_MINUTE', 5),

    /** Direktori kerja berkas PDF sementara (relatif storage_path); dihapus segera setelah terkirim. */
    'direktori_kerja' => 'app/private/laporan-bgn-tmp',
];
